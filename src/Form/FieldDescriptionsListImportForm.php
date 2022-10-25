<?php

namespace Drupal\field_descriptions_list\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

class FieldDescriptionsListImportForm extends FormBase {

  /**
   * The Entity type manager service.
   *
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * FieldDescriptionsListImportForm constructor.
   *
   * @param EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId() {
    return 'field_descriptions_list_import_form';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = [];

    $validators = array(
      'file_validate_extensions' => ['csv'],
    );

    $form['import_file'] = [
      '#type' => 'managed_file',
      '#name' => 'csv_import_file',
      '#title' => t('File'),
      '#multiple' => FALSE,
      '#size' => 50,
      '#description' => $this->t('CSV format only.'),
      '#default_value' => NULL,
      '#upload_validators' => $validators,
      '#upload_location' => 'public://',
      '#attributes' => ['class' => ['file-import-input']],
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
    ];

    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $fid = $form_state->getValue('import_file')[0];

    if ($fid !== NULL) {
      // Get the CSV file, mark as temporary.
      $file = $this->getCsvEntity($fid);
      $file->isTemporary();
      $file->save();

      // The keys we need to find the field.
      $keys = ['Entity type', 'Bundle machine ID', 'Field machine ID', 'Description'];

      // Retrieve an array of records.
      $records = $this->getCsvRecords($fid);

      // Peek at the first record and confirm it has the keys we need.
      if ($first = reset($records)) {
        if (count(array_intersect($keys, array_keys($first))) <> count($keys)) {
          return;
        }
      }

      /* @var array $record */
      foreach ($records as $record) {
        if (!$record['Description']) {
          continue;
        }

        /* @var \Drupal\field\FieldConfigInterface $field */
        $field = FieldConfig::loadByName($record['Entity type'], $record['Bundle machine ID'], $record['Field machine ID']);

        // If we found a field, set the description and save the field configuration in database.
        if ($field) {
          $field->setDescription($record['Description']);
          $field->save();
        }
      }
    }
  }

  /**
   * Parses a uploaded CSV file given a file ID.
   *
   * @param int $id
   *   The file ID
   * @param bool $skip_header
   *
   * @return array
   *   The parsed CSV
   */
  public function getCsvRecords(int $id, bool $skip_header = TRUE) {
    /* @var \Drupal\file\Entity\File $entity */
    $entity = $this->getCsvEntity($id);
    $return = [];

    if (($csv = fopen($entity->uri->getString(), 'r')) !== FALSE) {
      $header_keys = [];
      while (($row = fgetcsv($csv, 0, ',')) !== FALSE) {
        // Skip header row.
        if ($skip_header) {
          // Set the header row as the keys to the associative array of records.
          $header_keys = $row;
          $skip_header = FALSE;
          continue;
        }

        // Combine the row of data with the header keys.
        $return[] = array_combine($header_keys, $row);
      }
      fclose($csv);
    }

    return $return;
  }

  /**
   * Returns a file entity by ID.
   *
   * @param int $id
   *   The File ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The uploaded CSV file.
   *
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function getCsvEntity(int $id) {
    if ($id) {
      return $this->entityTypeManager->getStorage('file')->load($id);
    }
    else {
      return NULL;
    }
  }

}
