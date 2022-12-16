<?php

namespace Drupal\descriptions_list\Form;

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
   * Uploaded file entity.
   *
   * @var \Drupal\file\Entity\File
   */
  protected $file;

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
    return 'descriptions_list_import_form';
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

    // Fieldset for optional downloading of CSV file.
    $form['import_file'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Import file'),
      '#attributes' => ['class' => ['fieldset-no-legend']],
    ];

    $form['import_file']['file'] = [
      '#type' => 'managed_file',
      '#name' => 'csv_import_file',
      '#multiple' => FALSE,
      '#size' => 50,
      '#description' => t('Allowed file types: @extensions.', ['@extensions' => '.csv']),
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
      '#prefix' => '<div id="descriptions_list_import_summary_wrapper">',
      '#suffix' => '</div>',
      // The #markup element forces rendering of the #prefix and #suffix.
      // Without content, the wrappers are not rendered. Therefore, an empty
      // string is declared, ensuring that the wrapper for the search results
      // is present when the page is loaded.
      '#markup' => '',
    ];

    // The triggering element is the button that triggered the form submit. This
    // will be empty on initial page load, as the form has not been submitted
    // yet. The code inside the conditional is only executed when a
    // value has been submitted, and there are results to be rendered.
    if ($form_state->getTriggeringElement()) {
      $import_summary = $this->processCSVImport($form_state);

      if (empty($import_summary)) {
        $markup = $this->t("Error processing file");
      }
      else {
        $markup = $this->t("Processed: @processed, Added: @added, Modified: @modified, Deleted: @deleted", [
          '@processed' => $import_summary['processed'],
          '@added' => $import_summary['added'],
          '@modified' => $import_summary['modified'],
          '@deleted' => $import_summary['deleted'],
        ]);
      }

      // Form element that displays the result list.
      $form['import_summary']['result'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $markup . '</p>',
      ];
    }

    $form['actions']['#type'] = 'actions';

    // The submit button.
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        // The ID of the <div/> into which search results should be inserted.
        'wrapper' => 'descriptions_list_import_summary_wrapper',
      ],
    ];

    return $form;
  }

  /**
   * Custom ajax submit handler for the form. Returns search results.
   *
   * @param array $form
   *   The form itself.
   * @param FormStateInterface $form_state
   *
   * @return array
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    // Return the search results element of the form.
    return $form['import_summary'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Only validate the file exists when the file upload button is pressed.
    if ($form_state->getTriggeringElement()['#name'] == 'file_upload_button') {
      $this->file = _file_save_upload_from_form($form['import_file']['file'], $form_state, 0);

      // Get the CSV file, mark as temporary.
      $this->file->isTemporary();
      $this->file->save();

      // Ensure we have the file uploaded.
      if (!$this->file) {
        $form_state->setErrorByName('file', $this->t('File to import not found.'));
      }
    }

    if ($form_state->getTriggeringElement()['#name'] == 'op') {
      // Ensure we have the file uploaded.
      if (!$this->file) {
        $form_state->setErrorByName('file', $this->t('No file specified.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Set the form to rebuild. The submitted values are maintained in the
    // form state, and used to build the search results in the form definition.
    $form_state->setRebuild(TRUE);
  }

  /**
   * Processes the rows in the CSV import file.
   *
   * @param FormStateInterface $form_state
   */
  public function processCSVImport(FormStateInterface $form_state) {
    $processed = $modified = $added = $deleted = 0;

    // The keys we need to find the field.
    $keys = ['Entity type', 'Bundle machine ID', 'Field machine ID', 'Description'];

    // Retrieve an array of records from the file.
    $records = $this->getCsvRecords();

    // Peek at the first record and confirm it has the keys we need.
    if ($first = reset($records)) {
      if (count(array_intersect($keys, array_keys($first))) <> count($keys)) {
        return [];
      }
    }

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

    return [
      'processed' => $processed,
      'added' => $added,
      'modified' => $modified,
      'deleted' => $deleted,
    ];
  }

  /**
   * Parses a uploaded CSV file given a file ID.
   *
   * @param bool $header_row
   *
   * @return array
   *   The parsed CSV as an associative array. The keys of the array are the header columns.
   */
  public function getCsvRecords(bool $header_row = TRUE) {
    $return = [];

    if (($csv = fopen($this->file->uri->getString(), 'r')) !== FALSE) {
      $header_keys = [];

      while (($row = fgetcsv($csv, 0, ',')) !== FALSE) {
        // Skip header row.
        if ($header_row) {
          // Set the header row as the keys to the associative array of records.
          $header_keys = $row;
          $header_row = FALSE;
          continue;
        }

        // Combine the row of data with the header keys.
        $return[] = array_combine($header_keys, $row);
      }
      fclose($csv);
    }

    return $return;
  }

}
