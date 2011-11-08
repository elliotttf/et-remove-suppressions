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

  $cursor = $db->suppressions->find();
  foreach ($cursor as $doc) {
    $mail = $doc['mail'];
    $affected_mails[] = $mail;
    $u_newsletters = array();
    foreach ($doc as $k => $v) {
      if (is_numeric($k) && $v === TRUE) {
        $objects[] = remove_from_newsletter($k, $mail);
        $u_newsletters[] = getTitle($k);
      }
    }

    if (count($u_newsletters)) {
      echo 'Removing ' . $mail . ' from:' . PHP_EOL . '  ' . implode(PHP_EOL . '  ', $u_newsletters) . PHP_EOL;
    }

    // After we've collected 20 documents, batch them up and send the API request.
    if ($x != 0 && $x % 20 == 0) {
      $request = new ExactTarget_DeleteRequest();
      $options = new ExactTarget_DeleteOptions();
      $options->RequestType = ExactTarget_RequestType::Synchronous;

      $request->Options = $options;
      $request->Objects = $objects;

      $objects = array();

      // DANGER ZONE! Uncomment this when you're ready to go live for really realz.
      /*
      $result = ExactTarget::instance()->delete($request);
      if ($result->OverallStatus != 'OK') {
        echo 'ERROR: ' . $result->OverallStatus . PHP_EOL;
        echo '  There was a problem removing the following users from data extensions on ExactTarget:' . PHP_EOL . '    ' .
          implode(PHP_EOL . '    ', $affected_mails) . PHP_EOL;
        echo '  You should re-sync these users as soon as possible.' . PHP_EOL;
      }
      */
      $affected_mails = array();
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
  $de_map = array(
    'Global Suppression List' => '4D400C30-25D0-4321-A4AE-D1FF2B7C32C6',
    'Newsletter Status - Mature Inactive' => '2E8CCFA2-7EC9-4227-9188-95AEE3C1D081',
    'Editors highlights - Exclusion List' => 'F16BA19A-E363-4976-B4F6-72D7071DCA4C',
    'Editors highlights - Suppression List' => '69ECF034-E56A-4321-9005-8457D7D4327F',
    'Politics this week - Suppression List' => '2385F94F-0AED-4B7B-A83E-C358E7CFA792',
    'Business this week - Suppression List' => '4603EA33-22D5-480C-AD59-52C027A3FBEA',
    'New on TEo - Unsubscribe Exclusion List' => '31D50BD0-090B-40FC-A504-F98DCE95A0D0',
    'New on The Economist online - Suppression List' => 'C2DE85A4-79EE-4301-8B90-1805BF929324',
    'Management thinking - Suppression List' => 'CB20ED2F-9615-4F62-9C8E-A3A40E9D2B6E',
    'The Economist Debates - Suppression List' => '2E7CC02A-CFC1-40BC-B990-C357353691AB',
    'Gullivers best Unsubscribes' => 'AE4FF5F7-1220-4B00-8749-D0EE9C7277AD',
    'Gullvers best - Exclusion List' => '216BFA8B-E43E-464D-BB72-B5C5B4D6186E',
    'Gullivers best - Suppression List' => '2642B4F6-BEC8-41C7-863D-FC503C87AAF4',
  );
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

          // Track which suppression list the user came from.
          if ($obj != NULL && isset($obj['suppressed'])) {
            $newsletters['suppressed'] = $obj['suppressed'];
            $newsletters['suppressed'][] = $de_map[$sup_list];
          }
          else {
            $newsletters['suppressed'] = array($de_map[$sup_list]);
          }

          $count = 0;
          $success = FALSE;
          while ($count < 5 && !$success) {
            try {
              $db->suppressions->update(array('mail' => $prop->Value), array('$set' => $newsletters), array('upsert' => TRUE, 'safe' => TRUE));
              $success = TRUE;
            }
            catch (MongoCursorException $e) {
              echo 'ERROR: There was a problem writing to the database for user with mail: ' . $prop->Value . PHP_EOL;
            }
            $count++;
          }
          break;
        }
      }
    }
  }
  else {
    echo 'ERROR! ' . $results->OverallStatus . PHP_EOL;
    // Trick the system into retrying the request.
    $results->RequestID = $request_id;
    $results->OverallStatus = 'MoreDataAvailable';
  }

  // Try to free up some memory.
  unset($results->Results);

  if ($results->OverallStatus == 'MoreDataAvailable') {
    et_remove_suppressions($sup_list, $newsletter, $results->RequestID);
  }
}

// Global suppression is special since it applies to all lists.
et_remove_suppressions('Global Suppression List');
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

