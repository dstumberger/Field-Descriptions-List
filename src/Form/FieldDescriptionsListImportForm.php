<?php

namespace Drupal\field_descriptions_list\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

class FieldDescriptionsListImportForm extends FormBase {

  /**
   * The Entity type manager service.
   *
   * @var EntityTypeManagerInterface $entityTypeManager
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

    $caption = '<p>' . $this->t("Add, delete, or update entity field descriptions from a CSV file. File must have a header row with the the following columns:") . '</p>';
    $caption .= '<ul><li>"Entity type"</li><li>"Bundle machine ID"</li><li>"Field machine ID"</li><li>"Description"</li></ul>';
    $caption .= "<p>Note that all changes are made 'in database' and must be exported as configuration changes for software control.";
    $form['description'] = ['#markup' => $caption];

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

    // The wrapper for Ajax results list.
    $form['import_summary'] = [
      // Set the results to be below the form.
      '#weight' => 100,
      // The prefix/suffix are the div with the ID specified as the wrapper in
      // the submit button's #ajax definition.
      '#prefix' => '<div id="field_descriptions_list_import_summary_wrapper">',
      '#suffix' => '</div>',
      // The #markup element forces rendering of the #prefix and #suffix.
      // Without content, the wrappers are not rendered. Therefore, an empty
      // string is declared, ensuring that the wrapper for the search results
      // is present when the page is loaded.
      '#markup' => '',
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

      $processed = $modified = $added = $deleted = 0;

      /* @var array $record */
      foreach ($records as $record) {
        $processed++;

        /* @var \Drupal\field\FieldConfigInterface $field */
        $field = FieldConfig::loadByName($record['Entity type'], $record['Bundle machine ID'], $record['Field machine ID']);

        // If we found a field, set the description and save the field configuration in database.
        if ($field) {
          $existing = $field->getDescription();
          $new = $record['Description'];

          if (empty($existing) && empty($new)) {
            continue;
          }
          elseif (empty($existing) && !empty($new)) {
            $added++;
          }
          elseif (!empty($existing) && empty($new)) {
            $deleted++;
          }
          elseif (!empty($existing) && !empty($new)) {
            if (strcmp($existing, $new) == 0) {
              continue;
            }
            else {
              $modified++;
            }
          }

          $field->setDescription($new);
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
   * @return EntityInterface|null
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
