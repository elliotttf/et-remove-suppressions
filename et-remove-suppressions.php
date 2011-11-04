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
  $objects = array();

  $cursor = $db->supressions->find();
  foreach ($cursor as $doc) {
    $mail = $doc['mail'];
    $u_newsletters = array();
    foreach ($doc as $k => $v) {
      if (is_numeric($k) && $v === TRUE) {
        $objects[] = remove_from_newsletter($k, $mail);
        $u_newsletters[] = getTitle($k);
      }
    }

    if (count($u_newsletters)) {
      echo 'Removing ' . $mail . ' from: ' . implode(', ', $u_newsletters);
    }

    // After we've collected 20 documents, batch them up and send the API request.
    if ($x != 0 && $x % 20 == 0) {
      $request = new ExactTarget_DeleteRequest();
      $options = new ExactTarget_DeleteOptions();
      $options->RequestType = ExactTarget_RequestType::Asynchronous;

      $request->Options = $options;
      $request->Objects = $objects;

      $objects = array();

print_r($request);
      // DANGER ZONE! Uncomment this when you're ready to go live for really realz.
      //$result = ExactTarget::instance()->delete($request);
      //print_r($result);
    }
    $x++;
  }
}

function remove_from_newsletter($newsletter, $email) {
  $client = new ExactTarget_ClientID();
  $client->ID = EC_EXACT_TARGET_CLIENT_ID;

  $object = new ExactTarget_DataExtensionObject();
  $object->Client = $client;
  $object->CustomerKey = ec_exact_target_get_key($newsletter);
  $object->Keys = array();

  $prop = new ExactTarget_APIProperty();
  $prop->Name = 'Email Address';
  $prop->Value = $email;
  $object->Keys[] = $prop;

  return ExactTarget::encode($object, 'DataExtensionObject');
}

function et_remove_suppressions($sup_list, $newsletter = NULL, $request_id = NULL) {
  if ($newsletter == NULL) {
    //echo 'Collecting users on ' . $sup_list . ' to be removed from all lists' . PHP_EOL;
  }
  else {
    //echo 'Collecting users on ' . $sup_list . ' to be removed from ' . getTitle($newsletter) . PHP_EOL;
  }
  $m = new Mongo();
  $db = $m->selectDB('et');

  $properties = array('Email Address');
  $results = ETUtils::instance()->search(
    'DataExtensionObject[' . $sup_list . ']',
    EC_EXACT_TARGET_CLIENT_ID,
    $properties,
    NULL,
    TRUE,
    $request_id
  );
  if ($results->OverallStatus == 'OK' || $results->OverallStatus == 'MoreDataAvailable') {
    // SOAP sucks balls and sometimes returns a single object rather than an array.
    if (is_object($results->Results)) {
      $results->Results = array($results->Results);
    }
    foreach ($results->Results as $result) {
      $newsletters = array(
        21016203 => FALSE,
        21016204 => FALSE,
        21016205 => FALSE,
        21016206 => FALSE,
        21016207 => FALSE,
        21016208 => FALSE,
        21016209 => FALSE,
        21016210 => FALSE,
        21016211 => FALSE,
      );
      // Deal with more SOAP bullshit.
      if (is_object($result->Properties->Property)) {
        $result->Properties->Property = array($result->Properties->Property);
      }
      // Find the right property.
      foreach ($result->Properties->Property as $prop) {
        if ($prop->Name == 'Email Address') {
          while (!lock_acquire('et_remove:' . $prop->Value)) {
            sleep(1);
          }
          if ($newsletter == NULL) {
            $keys = array_keys($newsletters);
            // Mature inactive affects all lists EXCEPT BTW and PTW.
            if ($sup_list == 'Newsletter Status - Mature Inactive') {
              // If the user already exists make sure we're not going to nuke anything.
              $obj = $db->suppressions2->findOne(array('mail' => $prop->Value));
              if ($obj != NULL) {
                if (isset($obj[21016208]) && $obj[21016208] === FALSE) {
                  unset($keys[21016208]);
                }
                if (isset($obj[21016209]) && $obj[21016209] === FALSE) {
                  unset($keys[21016209]);
                }
              }
              else {
                unset($keys[21016208]);
                unset($keys[21016209]);
              }
            }
            $newsletters = array_fill_keys(array_keys($newsletters), TRUE);
          }
          else {
            // Pull out the values we already stored so we don't overwrite them.
            $obj = $db->suppressions2->findOne(array('mail' => $prop->Value));
            if ($obj != NULL) {
              foreach ($obj as $k => $v) {
                if (is_numeric($k)) {
                  $newsletters[$k] = $v;
                }
              }
            }
            $newsletters[$newsletter] = TRUE;
          }
          $db->suppressions2->update(array('mail' => $prop->Value), array('$set' => $newsletters), array('upsert' => TRUE));
          lock_release('et_remove:' . $prop->Value);
          break;
        }
      }
    }
  }
  else {
    echo 'ERROR! ' . $results->OverallStatus . PHP_EOL;
  }

  // Try to free up some memory.
  unset($results->Results);

  if ($results->OverallStatus == 'MoreDataAvailable') {
    et_remove_suppressions($sup_list, $newsletter, $results->RequestID);
  }
}

$suppressions = array(
  1 => array(
    'name' => 'Global Suppression List',
    'nid' => NULL,
  ),
  2 => array(
    'name' => 'Newsletter Status - Mature Inactive',
    'nid' => NULL
  ),
  3 => array(
    'name' => 'Editors highlights - Exclusion List', 
    'nid' => 21016205
  ),
  4 => array(
    'name' => 'Editors highlights - Suppression List',
    'nid' => 21016205
  ),
  5 => array(
    'name' => 'Politics this week - Suppression List',
    'nid' => 21016208
  ),
  6 => array(
    'name' => 'Business this week - Suppression List',
    'nid' => 21016209
  ),
  7 => array(
    'name' => 'New on TEo - Unsubscribe Exclusion List',
    'nid' => 21016204
  ),
  8 => array(
    'name' => 'New on The Economist online - Suppression List',
    'nid' => 21016204
  ),
  9 => array(
    'name' => 'Management thinking - Suppression List',
    'nid' => 21016207
  ),
  10 => array(
    'name' => 'The Economist Debates - Suppression List',
    'nid' => 21016211
  ),
  11 => array(
    'name' => 'Gullivers best Unsubscribes',
    'nid' => 21016210
  ),
  12 => array(
    'name' => 'Gullvers best - Exclusion List',
    'nid' => 21016210
  ),
  13 => array(
    'name' => 'Gullivers best - Suppression List',
    'nid' => 21016210
  ),
);

foreach ($suppressions as $k => $v) {
  switch ($pid = pcntl_fork()) {
    case -1:
      exit(1);
    case 0:
      echo "Starting process for collecting " . $v['name'];
      et_remove_suppressions($v['name'], $v['nid']);
      break;
    default:
      pcntl_waitpid($pid, $status);
      echo 'Finished collecting ' . $v['name'] . PHP_EOL;
      break;
  }
}

// Send the API calls for all the removals.
et_send_removals();

