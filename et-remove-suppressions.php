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
    echo 'Collecting users on "' . $sup_list . '" to be removed from all lists' . PHP_EOL;
  }
  else {
    echo 'Collecting users on "' . $sup_list . '" to be removed from "' . getTitle($newsletter) . '"' . PHP_EOL;
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
          if ($newsletter == NULL) {
            $newsletters = array_fill_keys(array_keys($newsletters), TRUE);
            // Mature inactive affects all lists EXCEPT BTW and PTW.
            if ($sup_list == 'Newsletter Status - Mature Inactive') {
              $newsletters[21016208] = FALSE;
              $newsletters[21016209] = FALSE;
              // If the user already exists make sure we're not going to nuke anything.
              $obj = $db->suppressions->findOne(array('mail' => $prop->Value));
              if ($obj != NULL) {
                if (isset($obj[21016208]) && $obj[21016208] === TRUE) {
                  $newsletters[21016208] = TRUE;
                }
                if (isset($obj[21016209]) && $obj[21016209] === TRUE) {
                  $newsletters[21016209] = TRUE;
                }
              }
            }
          }
          else {
            // Pull out the values we already stored so we don't overwrite them.
            $obj = $db->suppressions->findOne(array('mail' => $prop->Value));
            if ($obj != NULL) {
              foreach ($obj as $k => $v) {
                if (is_numeric($k)) {
                  $newsletters[$k] = $v;
                }
              }
            }
            $newsletters[$newsletter] = TRUE;
          }
          $db->suppressions->update(array('mail' => $prop->Value), array('$set' => $newsletters), array('upsert' => TRUE));
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

// Global suppression is special since it applies to all lists.
//et_remove_suppressions('Global Suppression List');
et_remove_suppressions('Newsletter Status - Mature Inactive');

// Remove users from Editor's Highlights newsletter.
et_remove_suppressions('Editors highlights - Exclusion List', 21016205);
et_remove_suppressions('Editors highlights - Suppression List', 21016205);

// Remove users from Politics this week newsletter.
et_remove_suppressions('Politics this week - Suppression List', 21016208);

// Remove users from Business this week newsletter.
et_remove_suppressions('Business this week - Suppression List', 21016209);

// Remove users from New on TEo newsletter.
et_remove_suppressions('New on TEo - Unsubscribe Exclusion List', 21016204);
et_remove_suppressions('New on The Economist online - Suppression List', 21016204);

// Remove users from Management thinking newsletter.
et_remove_suppressions('Management thinking - Suppression List', 21016207);

// Remove users from The Economist debates newsletter.
et_remove_suppressions('The Economist Debates - Suppression List', 21016211);

// Remove users from Gulliver's Best newsletter.
et_remove_suppressions('Gullivers best Unsubscribes', 21016210);
et_remove_suppressions('Gullvers best - Exclusion List', 21016210);
et_remove_suppressions('Gullivers best - Suppression List', 21016210);

// Send the API calls for all the removals.
et_send_removals();

