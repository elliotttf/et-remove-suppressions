<?php

function remove_from_newsletter($newsletter, $email) {
  echo 'Removing ' . $email . ' from ' . $newsletter . PHP_EOL;

  $request = new ExactTarget_DeleteRequest();
  $options = new ExactTarget_DeleteOptions();
  $options->RequestType = ExactTarget_RequestType::Asynchronous;

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

  $object = ExactTarget::encode($object, 'DataExtensionObject');

  $request->Options = $options;
  $request->Objects = array($object);

  // DANGER ZONE! Uncomment this when you're ready to go live for really realz.
  //$result = ExactTarget::instance()->delete($request);
  //print_r($result);
}

function et_remove_suppressions($sup_list, $newsletter, $request_id = NULL) {
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
    if (is_array($results->Results)) {
      foreach ($results->Results as $result) {
        // Find the right property.
        foreach ($result->Properties as $prop) {
          if ($prop->Name == 'Email Address') {
            remove_from_newsletter($newsletter, $prop->Value);
            break;
          }
        }
      }
    }
    else {
      // Find the right property.
      foreach ($result->Properties as $prop) {
        if ($prop->Name == 'Email Address') {
          remove_from_newsletter($newsletter, $prop->Value);
          break;
        }
      }
    }
  }
  else {
    print_r('ERROR! ' . $results->OverallStatus);
  }
  if ($results->OverallStatus == 'MoreDataAvailable') {
    et_remove_suppressions($sup_list, $newsletter, $results->RequestID);
  }
}

// Remove users from Gulliver's Best newsletter.
et_remove_suppressions('Gullivers best Unsubscribes', 21016210);
et_remove_suppressions('Gullvers best - Exclusion List', 21016210);
et_remove_suppressions('Global Suppression List', 21016210);
et_remove_suppressions('Newsletter Status - Mature Inactive', 21016210);
et_remove_suppressions('Gullivers best - Suppression List', 21016210);
