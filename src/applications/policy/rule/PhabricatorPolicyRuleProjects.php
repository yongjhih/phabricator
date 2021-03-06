<?php

final class PhabricatorPolicyRuleProjects
  extends PhabricatorPolicyRule {

  private $memberships = array();

  public function getRuleDescription() {
    return pht('members of projects');
  }

  public function willApplyRules(PhabricatorUser $viewer, array $values) {
    $values = array_unique(array_filter(array_mergev($values)));
    if (!$values) {
      return;
    }

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withMemberPHIDs(array($viewer->getPHID()))
      ->withPHIDs($values)
      ->execute();
    foreach ($projects as $project) {
      $this->memberships[$viewer->getPHID()][$project->getPHID()] = true;
    }
  }

  public function applyRule(PhabricatorUser $viewer, $value) {
    foreach ($value as $project_phid) {
      if (isset($this->memberships[$viewer->getPHID()][$project_phid])) {
        return true;
      }
    }
    return false;
  }

  public function getValueControlType() {
    return self::CONTROL_TYPE_TOKENIZER;
  }

  public function getValueControlTemplate() {
    $projects_source = new PhabricatorProjectDatasource();

    return array(
      'markup' => new AphrontTokenizerTemplateView(),
      'uri' => $projects_source->getDatasourceURI(),
      'placeholder' => $projects_source->getPlaceholderText(),
    );
  }

  public function getRuleOrder() {
    return 200;
  }

  public function getValueForStorage($value) {
    PhutilTypeSpec::newFromString('list<string>')->check($value);
    return array_values($value);
  }

  public function getValueForDisplay(PhabricatorUser $viewer, $value) {
    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($viewer)
      ->withPHIDs($value)
      ->execute();

    return mpull($handles, 'getFullName', 'getPHID');
  }

  public function ruleHasEffect($value) {
    return (bool)$value;
  }

}
