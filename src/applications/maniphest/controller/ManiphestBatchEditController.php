<?php

final class ManiphestBatchEditController extends ManiphestController {

  public function processRequest() {
    $this->requireApplicationCapability(
      ManiphestBulkEditCapability::CAPABILITY);

    $request = $this->getRequest();
    $user = $request->getUser();

    $task_ids = $request->getArr('batch');
    $tasks = id(new ManiphestTaskQuery())
      ->setViewer($user)
      ->withIDs($task_ids)
      ->requireCapabilities(
        array(
          PhabricatorPolicyCapability::CAN_VIEW,
          PhabricatorPolicyCapability::CAN_EDIT,
        ))
      ->execute();

    $actions = $request->getStr('actions');
    if ($actions) {
      $actions = json_decode($actions, true);
    }

    if ($request->isFormPost() && is_array($actions)) {
      foreach ($tasks as $task) {
        $field_list = PhabricatorCustomField::getObjectFields(
          $task,
          PhabricatorCustomField::ROLE_EDIT);
        $field_list->readFieldsFromStorage($task);

        $xactions = $this->buildTransactions($actions, $task);
        if ($xactions) {
          // TODO: Set content source to "batch edit".

          $editor = id(new ManiphestTransactionEditor())
            ->setActor($user)
            ->setContentSourceFromRequest($request)
            ->setContinueOnNoEffect(true)
            ->setContinueOnMissingFields(true)
            ->applyTransactions($task, $xactions);
        }
      }

      $task_ids = implode(',', mpull($tasks, 'getID'));

      return id(new AphrontRedirectResponse())
        ->setURI('/maniphest/?ids='.$task_ids);
    }

    $handles = ManiphestTaskListView::loadTaskHandles($user, $tasks);

    $list = new ManiphestTaskListView();
    $list->setTasks($tasks);
    $list->setUser($user);
    $list->setHandles($handles);

    $template = new AphrontTokenizerTemplateView();
    $template = $template->render();

    $projects_source = new PhabricatorProjectDatasource();
    $mailable_source = new PhabricatorMetaMTAMailableDatasource();
    $owner_source = new PhabricatorTypeaheadOwnerDatasource();

    require_celerity_resource('maniphest-batch-editor');
    Javelin::initBehavior(
      'maniphest-batch-editor',
      array(
        'root' => 'maniphest-batch-edit-form',
        'tokenizerTemplate' => $template,
        'sources' => array(
          'project' => array(
            'src'           => $projects_source->getDatasourceURI(),
            'placeholder'   => $projects_source->getPlaceholderText(),
          ),
          'owner' => array(
            'src'           => $owner_source->getDatasourceURI(),
            'placeholder'   => $owner_source->getPlaceholderText(),
            'limit'         => 1,
          ),
          'cc'    => array(
            'src'           => $mailable_source->getDatasourceURI(),
            'placeholder'   => $mailable_source->getPlaceholderText(),
          )
        ),
        'input' => 'batch-form-actions',
        'priorityMap' => ManiphestTaskPriority::getTaskPriorityMap(),
        'statusMap'   => ManiphestTaskStatus::getTaskStatusMap(),
      ));

    $form = new AphrontFormView();
    $form->setUser($user);
    $form->setID('maniphest-batch-edit-form');

    foreach ($tasks as $task) {
      $form->appendChild(
        phutil_tag(
          'input',
          array(
            'type' => 'hidden',
            'name' => 'batch[]',
            'value' => $task->getID(),
          )));
    }

    $form->appendChild(
      phutil_tag(
        'input',
        array(
          'type' => 'hidden',
          'name' => 'actions',
          'id'   => 'batch-form-actions',
        )));
    $form->appendChild(
      phutil_tag('p', array(), pht('These tasks will be edited:')));
    $form->appendChild($list);
    $form->appendChild(
      id(new AphrontFormInsetView())
        ->setTitle('Actions')
        ->setRightButton(javelin_tag(
            'a',
            array(
              'href' => '#',
              'class' => 'button green',
              'sigil' => 'add-action',
              'mustcapture' => true,
            ),
            pht('Add Another Action')))
        ->setContent(javelin_tag(
          'table',
          array(
            'sigil' => 'maniphest-batch-actions',
            'class' => 'maniphest-batch-actions-table',
          ),
          '')))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Update Tasks'))
          ->addCancelButton('/maniphest/'));

    $title = pht('Batch Editor');

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb($title);

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Batch Edit Tasks'))
      ->setForm($form);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form_box,
      ),
      array(
        'title' => $title,
        'device' => false,
      ));
  }

  private function buildTransactions($actions, ManiphestTask $task) {
    $value_map = array();
    $type_map = array(
      'add_comment'     => PhabricatorTransactions::TYPE_COMMENT,
      'assign'          => ManiphestTransaction::TYPE_OWNER,
      'status'          => ManiphestTransaction::TYPE_STATUS,
      'priority'        => ManiphestTransaction::TYPE_PRIORITY,
      'add_project'     => ManiphestTransaction::TYPE_PROJECTS,
      'remove_project'  => ManiphestTransaction::TYPE_PROJECTS,
      'add_ccs'         => ManiphestTransaction::TYPE_CCS,
      'remove_ccs'      => ManiphestTransaction::TYPE_CCS,
    );

    $edge_edit_types = array(
      'add_project'    => true,
      'remove_project' => true,
      'add_ccs'        => true,
      'remove_ccs'     => true,
    );

    $xactions = array();
    foreach ($actions as $action) {
      if (empty($type_map[$action['action']])) {
        throw new Exception("Unknown batch edit action '{$action}'!");
      }

      $type = $type_map[$action['action']];

      // Figure out the current value, possibly after modifications by other
      // batch actions of the same type. For example, if the user chooses to
      // "Add Comment" twice, we should add both comments. More notably, if the
      // user chooses "Remove Project..." and also "Add Project...", we should
      // avoid restoring the removed project in the second transaction.

      if (array_key_exists($type, $value_map)) {
        $current = $value_map[$type];
      } else {
        switch ($type) {
          case PhabricatorTransactions::TYPE_COMMENT:
            $current = null;
            break;
          case ManiphestTransaction::TYPE_OWNER:
            $current = $task->getOwnerPHID();
            break;
          case ManiphestTransaction::TYPE_STATUS:
            $current = $task->getStatus();
            break;
          case ManiphestTransaction::TYPE_PRIORITY:
            $current = $task->getPriority();
            break;
          case ManiphestTransaction::TYPE_PROJECTS:
            $current = $task->getProjectPHIDs();
            break;
          case ManiphestTransaction::TYPE_CCS:
            $current = $task->getCCPHIDs();
            break;
        }
      }

      // Check if the value is meaningful / provided, and normalize it if
      // necessary. This discards, e.g., empty comments and empty owner
      // changes.

      $value = $action['value'];
      switch ($type) {
        case PhabricatorTransactions::TYPE_COMMENT:
          if (!strlen($value)) {
            continue 2;
          }
          break;
        case ManiphestTransaction::TYPE_OWNER:
          if (empty($value)) {
            continue 2;
          }
          $value = head($value);
          if ($value === ManiphestTaskOwner::OWNER_UP_FOR_GRABS) {
            $value = null;
          }
          break;
        case ManiphestTransaction::TYPE_PROJECTS:
          if (empty($value)) {
            continue 2;
          }
          break;
        case ManiphestTransaction::TYPE_CCS:
          if (empty($value)) {
            continue 2;
          }
          break;
      }

      // If the edit doesn't change anything, go to the next action. This
      // check is only valid for changes like "owner", "status", etc, not
      // for edge edits, because we should still apply an edit like
      // "Remove Projects: A, B" to a task with projects "A, B".

      if (empty($edge_edit_types[$action['action']])) {
        if ($value == $current) {
          continue;
        }
      }

      // Apply the value change; for most edits this is just replacement, but
      // some need to merge the current and edited values (add/remove project).

      switch ($type) {
        case PhabricatorTransactions::TYPE_COMMENT:
          if (strlen($current)) {
            $value = $current."\n\n".$value;
          }
          break;
        case ManiphestTransaction::TYPE_PROJECTS:
        case ManiphestTransaction::TYPE_CCS:
          $remove_actions = array(
            'remove_project' => true,
            'remove_ccs'    => true,
          );
          $is_remove = isset($remove_actions[$action['action']]);

          $current = array_fill_keys($current, true);
          $value   = array_fill_keys($value, true);

          $new = $current;
          $did_something = false;

          if ($is_remove) {
            foreach ($value as $phid => $ignored) {
              if (isset($new[$phid])) {
                unset($new[$phid]);
                $did_something = true;
              }
            }
          } else {
            foreach ($value as $phid => $ignored) {
              if (empty($new[$phid])) {
                $new[$phid] = true;
                $did_something = true;
              }
            }
          }

          if (!$did_something) {
            continue 2;
          }

          $value = array_keys($new);
          break;
      }

      $value_map[$type] = $value;
    }

    $template = new ManiphestTransaction();

    foreach ($value_map as $type => $value) {
      $xaction = clone $template;
      $xaction->setTransactionType($type);

      switch ($type) {
        case PhabricatorTransactions::TYPE_COMMENT:
          $xaction->attachComment(
            id(new ManiphestTransactionComment())
              ->setContent($value));
          break;
        case ManiphestTransaction::TYPE_PROJECTS:

          // TODO: Clean this mess up.
          $project_type = PhabricatorProjectObjectHasProjectEdgeType::EDGECONST;
          $xaction
            ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
            ->setMetadataValue('edge:type', $project_type)
            ->setNewValue(
              array(
                '=' => array_fuse($value),
              ));
          break;
        default:
          $xaction->setNewValue($value);
          break;
      }

      $xactions[] = $xaction;
    }

    return $xactions;
  }

}
