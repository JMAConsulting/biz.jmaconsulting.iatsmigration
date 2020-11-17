<?php

use CRM_Iatstocivicrm_ExtensionUtil as E;

class CRM_Iatsmigration_Utils {

  function _getPaymentProcessorId($type) {
    $id = civicrm_api3('PaymentProcessorType', 'getvalue', [
      'return' => "id",
      'name' => $type,
    ]);
    $paymentProcessor = civicrm_api3('PaymentProcessor', 'getvalue', [
      'return' => "id",
      'payment_processor_type_id' => $id,
      'is_test' => 0,
    ]);
    return $paymentProcessor;
  }

  public static function _createPaymentToken($customer, $cid, $cardType) {
    switch ($cardType) {
      case 'eft':
        $pp = self::_getPaymentProcessorId('iATS Payments ACH/EFT');
        break;
      case 'cc':
        $pp = self::_getPaymentProcessorId('iATS Payments Credit Card');
        break;
      default:
        break;
    }
    // Check to see if contact record already has a payment token.
    $token = civicrm_api3('PaymentToken', 'get', [
      'contact_id' => $cid,
      'payment_processor_id' => $pp,
      'token' => $customer['CustomerCode'],
    ]);
    if (empty($token['id'])) {
      // No token found, proceed to create.
      $token = civicrm_api3('PaymentToken', 'create', [
        'contact_id' => $cid,
        'payment_processor_id' => $pp,
        'token' => $customer['CustomerCode'],
        'expiry_date' => date('Y-m-d', strtotime($customer['EndDate(mm/dd/yyyy)'])),
      ]);
    }

    return [$pp, $token['id']];
  }

  function _createRecurRecord($customer, $cid, $type) {
    list($pp, $token) = self::_createPaymentToken($customer, $cid, $type);
    $recurParams = [
      'contact_id' => $cid,
      'amount' => $customer['Amount'],
      'currency' => 'CAD',
      'frequency_unit' => $customer['ScheduleType'],
      'frequency_interval' => $customer['FrequencyInterval'],
      'installments' => 0,
      'start_date' => date('Y-m-d', strtotime($customer['BeginDate'])),
      'create_date' => date('Y-m-d'),
      'end_date' => date('Y-m-d', strtotime($customer['EndDate'])),
      'payment_token_id' => $token,
      'contribution_status_id' => "In Progress",
      'next_sched_contribution_date' => date('Y-m-d', strtotime($customer['NextRunDate'])),
      'payment_processor_id' => $pp,
      'cycle_day' => $customer['ScheduleDateNumber'],
      'is_email_receipt' => 1,
    ];
    // Handle quarterly payments.
    return civicrm_api3('ContributionRecur', 'create', $recurParams)['id'];
  }

  public static function importCustomers($table) {
    $iats = CRM_Core_DAO::executeQuery("SELECT * FROM $table")->fetchAll();
    $results = [];
    $country = CRM_Core_DAO::executeQuery("SELECT id, iso_code FROM civicrm_country WHERE name LIKE 'Canada'")->fetchAll()[0];
    foreach ($iats as $customer) {
      $cid = NULL;
      // Create the contact.
      $params = [
        'first_name' => $customer['FirstName'],
        'last_name' => $customer['LastName'],
        'external_identifier' => $customer['PreviousTokenID'],
        'contact_type' => 'Individual',
        'sequential' => 1,
      ];
      // Check for existing contact.
      $existingContact = civicrm_api3('Contact', 'get', $params);
      if (!empty($existingContact['values'][0]['id'])) {
        $cid = $existingContact['values'][0]['id'];
      }
      else {
        // We create a new contact.
        $contact = civicrm_api3('Contact', 'create', $params);
        if (!empty($contact['id'])) {
          $cid = $contact['id'];
        }
      }
      if (!empty($cid)) {
        $results['Contacts Created'][] = $cid;
        // Create the address.
        $addressParams = [
          'street_address' => $customer['Address'],
          'city' => $customer['City'],
          'country' => $country['iso_code'],
          'postal_code' => $country['Zipcode'],
          'contact_id' => $cid,
          'location_type_id' => "Home",
        ];
        // Fetch state/province.
        if (!empty($customer['State'])) {
          $state = CRM_Core_DAO::singleValueQuery("SELECT name FROM civicrm_state_province WHERE (name = %1 OR abbreviation = %2) AND country_id = %3",
          [
            1 => [$customer['State'], 'String'],
            2 => [$customer['State'], 'String'],
            3 => [$country['id'], 'Integer'],
          ]);
          if (!empty($state)) {
            $addressParams['state_province_id'] = $state;
          }
        }
        self::_deleteEntities('Address', ['contact_id' => $cid]);
        civicrm_api3('Address', 'create', $addressParams);
      }
    }
    return $results;
  }

  public static function importRecurring($params) {
    $iats = CRM_Core_DAO::executeQuery("SELECT 
      CustomerCode,
CompanyName,
FirstName,
LastName,
FullName,
Address,
City,
State,
Zipcode,
Country,
Email,
Phone,
Mobile,
Comment,
CardType,
`Account or Card Number` AS CardNumber,
`ExpiryDate(MM/YY)` AS ExpiryDate,
AccountType,
Reoccuring,
Amount,
`BeginDate(mm/dd/yyyy)` AS BeginDate,
`EndDate(mm/dd/yyyy)` AS EndDate,
ScheduleType,
`ScheduleDateNumber(Weekly/Monthly Only)` AS ScheduleDateNumber,
`Next Run Date` AS NextRunDate,
`Interval (every 2 months or 3 months or every year)` AS FrequencyInterval
 FROM " . $params['table_name'])->fetchAll();
    $results = [];
    foreach ($iats as $customer) {
      // Get the customer using the external identifier, which is the payment token ID.
      $contact = civicrm_api3('Contact', 'get', [
        'external_identifier' => $customer['CustomerCode'],
        'sequential' => 1,
      ]);
      if (!empty($contact['values'][0]['id'])) {
        // Contact exists, proceed to create payment token and recurring contribution.
        $cid = $contact['values'][0]['id'];

        // We first fetch the new customer code from the previous table.
        $customer['CustomerCode'] = CRM_Core_DAO::singleValueQuery("SELECT New_customer_code FROM " . $params['token_table'] . " WHERE PreviousTokenID = %1",
        [1 => [$customer['CustomerCode'], 'String']]);

        // We now create the recurring contribution as well.
        $recur = self::_createRecurRecord($customer, $cid, $params['type']);
        $results['Recurring Contributions Created'][] = $recur;
      }
    }
    return $results;
  }

  function _deleteEntities($entity, $params) {
    $params += ['options' => ['limit' => 0]];
    $entities = civicrm_api3($entity, 'get', $params)['values'];
    foreach ($entities as $toDelete) {
      civicrm_api3($entity, 'delete', ['id' => $toDelete['id']]);
    }
  }
}