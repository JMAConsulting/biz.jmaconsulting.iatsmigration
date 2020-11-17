<?php
// This file declares a managed database record of type "Job".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
return [
  [
    'name' => 'Cron:IatsMigration.Importcustomers',
    'entity' => 'Job',
    'params' => [
      'version' => 3,
      'name' => 'Call IatsMigration.Importcustomers API',
      'description' => 'Call IatsMigration.Importcustomers API',
      'run_frequency' => 'Yearly',
      'api_entity' => 'IatsMigration',
      'api_action' => 'Importcustomers',
      'parameters' => 'table_name=iats_customer_import',
    ],
  ],
];
