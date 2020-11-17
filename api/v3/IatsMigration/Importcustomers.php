<?php
use CRM_Iatsmigration_ExtensionUtil as E;

/**
 * IatsMigration.Importcustomers API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_iats_migration_Importcustomers_spec(&$spec) {
  $spec['table_name']['api.required'] = 1;
}

/**
 * IatsMigration.Importcustomers API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @see civicrm_api3_create_success
 *
 * @throws API_Exception
 */
function civicrm_api3_iats_migration_Importcustomers($params) {
  if (array_key_exists('table_name', $params)) {
    $returnValues = CRM_Iatsmigration_Utils::importCustomers($params['table_name']);
    return civicrm_api3_create_success($returnValues, $params, 'IatsMigration', 'Importcustomers');
  }
  else {
    throw new API_Exception(/*error_message*/ 'Table name is required', /*error_code*/ 'tablename_required');
  }
}
