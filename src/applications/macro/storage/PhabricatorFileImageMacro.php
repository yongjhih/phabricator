<?php

final class PhabricatorFileImageMacro extends PhabricatorFileDAO
  implements
    PhabricatorSubscribableInterface,
    PhabricatorApplicationTransactionInterface,
    PhabricatorFlaggableInterface,
    PhabricatorPolicyInterface {

  protected $authorPHID;
  protected $filePHID;
  protected $name;
  protected $isDisabled = 0;
  protected $audioPHID;
  protected $audioBehavior = self::AUDIO_BEHAVIOR_NONE;
  protected $mailKey;

  private $file = self::ATTACHABLE;
  private $audio = self::ATTACHABLE;

  const AUDIO_BEHAVIOR_NONE   = 'audio:none';
  const AUDIO_BEHAVIOR_ONCE   = 'audio:once';
  const AUDIO_BEHAVIOR_LOOP   = 'audio:loop';

  public function attachFile(PhabricatorFile $file) {
    $this->file = $file;
    return $this;
  }

  public function getFile() {
    return $this->assertAttached($this->file);
  }

  public function attachAudio(PhabricatorFile $audio = null) {
    $this->audio = $audio;
    return $this;
  }

  public function getAudio() {
    return $this->assertAttached($this->audio);
  }

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID  => true,
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhabricatorMacroMacroPHIDType::TYPECONST);
  }


  public function save() {
    if (!$this->getMailKey()) {
      $this->setMailKey(Filesystem::readRandomCharacters(20));
    }
    return parent::save();
  }


/* -(  PhabricatorApplicationTransactionInterface  )------------------------- */


  public function getApplicationTransactionEditor() {
    return new PhabricatorMacroEditor();
  }

  public function getApplicationTransactionObject() {
    return $this;
  }

  public function getApplicationTransactionTemplate() {
    return new PhabricatorMacroTransaction();
  }


/* -(  PhabricatorSubscribableInterface  )----------------------------------- */


  public function isAutomaticallySubscribed($phid) {
    return false;
  }

  public function shouldShowSubscribersProperty() {
    return true;
  }

  public function shouldAllowSubscription($phid) {
    return true;
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
    );
  }

  public function getPolicy($capability) {
    return PhabricatorPolicies::getMostOpenPolicy();
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return false;
  }

  public function describeAutomaticCapability($capability) {
    return null;
  }

}
