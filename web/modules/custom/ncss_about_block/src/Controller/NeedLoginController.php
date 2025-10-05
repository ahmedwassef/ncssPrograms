<?php

namespace Drupal\ncss_about_block\Controller;

use Drupal\Core\Controller\ControllerBase;

class NeedLoginController extends ControllerBase
{
  function build(){
    $curr_lang = \Drupal::languageManager()->getCurrentLanguage()->getId();

    return array(
      '#theme' => 'need_login',
      '#items' => [],
      '#lang' => $curr_lang,
    );
  }

}
