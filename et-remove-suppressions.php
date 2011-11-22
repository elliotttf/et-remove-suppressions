<?php

function getTitle($nid) {
  static $newsletters = array();

  if (!isset($newsletters[$nid])) {
    $newsletters[$nid] = db_result(db_query("SELECT title FROM {node} WHERE nid=%d", $nid));
  }

  return $newsletters[$nid];
}

function et_send_removals() {
  $m = new Mongo();
  $db = $m->selectDB('et');
  $x = 0;
  $affected_mails = $objects = array();

  $cursor = $db->suppressions->find(array('processed' => FALSE));
  foreach ($cursor as $doc) {
    $uid = $doc['uid'];
    $mail = $doc['mail'];
    $affected_mails[] = $mail;
    $u_newsletters = array();
    foreach ($doc as $k => $v) {
      if (is_numeric($k) && $v === TRUE) {
        $objects[] = remove_from_newsletter($k, $uid);
        $u_newsletters[] = getTitle($k);
      }
    }

    if (count($u_newsletters)) {
      echo 'Removing ' . $mail . ' from:' . PHP_EOL . '  ' . implode(PHP_EOL . '  ', $u_newsletters) . PHP_EOL;
    }

    // After we've collected 20 documents, batch them up and send the API request.
    if ((($x + 1) > count($cursor)) || ($x != 0 && $x % 20 == 0)) {
      $request = new ExactTarget_DeleteRequest();
      $options = new ExactTarget_DeleteOptions();
      $options->RequestType = ExactTarget_RequestType::Asynchronous;

      $request->Options = $options;
      $request->Objects = $objects;
      $request->exact_target_soap_bypass_batch = TRUE;

      $objects = array();

      // DANGER ZONE! Uncomment this when you're ready to go live for really realz.
      echo "SENDING API REQUEST!" . PHP_EOL;
      $result = ExactTarget::instance()->delete($request);
      echo "RECEIVED RESULT " . $result->OverallStatus . PHP_EOL . PHP_EOL;
      echo "=================================" . PHP_EOL;
      if ($result->OverallStatus != 'OK') {
        echo 'ERROR: ' . $result->OverallStatus . PHP_EOL;
        echo '  There was a problem removing the following users from data extensions on ExactTarget:' . PHP_EOL . '    ' .
          implode(PHP_EOL . '    ', $affected_mails) . PHP_EOL;
        echo '  You should re-sync these users as soon as possible.' . PHP_EOL;
        echo '  Also, this was likely some more ET bullshit, move along.' . PHP_EOL;
      }
      // Mark the users as processed.
      foreach ($affected_mails as $am) {
        $db->suppressions->update(array('mail' => $am), array('$set' => array('processed' => TRUE)));
      }
      $affected_mails = array();
    }
    $x++;
  }
}

function remove_from_newsletter($newsletter, $uid, $key = NULL) {
  $client = new ExactTarget_ClientID();
  $client->ID = EC_EXACT_TARGET_CLIENT_ID;

  $object = new ExactTarget_DataExtensionObject();
  $object->Client = $client;
  $object->CustomerKey = ($key == NULL) ? ec_exact_target_get_key($newsletter) : $key;
  $object->Keys = array();

  $prop = new ExactTarget_APIProperty();
  $prop->Name = 'Drupal ID';
  $prop->Value = $uid;
  $object->Keys[] = $prop;

  return ExactTarget::encode($object, 'DataExtensionObject');
}

// Send the API calls for all the removals.
et_send_removals();

