<?php

namespace Drupal\field_descriptions_list\Form;

use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the entities_descriptions_list form.
 */
class FieldDescriptionsListEntityTypesForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var EntityFieldManager
   */
  protected $entityFieldManager;

  /**
   * The entity type bundle info provider.
   *
   * @var EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * Constructor.
   *
   * @param EntityFieldManager $entity_field_manager
   *   The entity field manager.
   * @param EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info provider.
   * @param EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityFieldManager $entity_field_manager,
      EntityTypeBundleInfoInterface $entity_type_bundle_info,
      EntityTypeManagerInterface $entity_type_manager) {
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Implements \Drupal\Core\Form\FormInterface::getFormID().
   */
  public function getFormId() {
    return 'field_descriptions_list_entities_form';
  }

  /**
   * {@inheritdoc}
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Check or uncheck all available entity types, see associated js file in library.
    $form['checkall'] = array(
      '#type' => 'checkbox',
      '#title' => t('Select / Unselect all'),
      '#weight' => -1,
      '#attributes' => ['class' => ['checkall-btn']],
      '#description' => $this->t("Select all or none of the entity types below regardless of previous selection."),
    );
    $form['checkall']['#attached']['library'][] = 'field_descriptions_list/field_descriptions_list_entities_form';

    // Entities list.
    $form['entities'] = [
      '#type' => 'fieldset',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['entities_fieldset']],
      '#description' => $this->t("Select which entity types to list."),
    ];

    $entity_types = [
      'node_type' => 'Node type (Content type)',
      'media_type' => 'Media type',
      'comment_type' => 'Comment type',
      'taxonomy_vocabulary' => 'Vocabulary',
      'paragraphs_type' => 'Paragraph type',
      'webform' => 'Webform',
      'block_content_type' => 'Block content type',
      'menu' => 'Menu'
    ];

    foreach($entity_types as $entity_type => $entity_type_label) {
      /* @var Drupal\Core\Config\Entity\ConfigEntityType $bundle_entity_type */
      if ($bundle_entity_type = $this->entityTypeManager->getDefinition($entity_type)) {
        $original_class = $bundle_entity_type->getOriginalClass();

        if (!method_exists($original_class, "getDescription")) {
          unset($entity_types[$entity_type]);
        }
      }
      else {
        unset($entity_types[$entity_type]);
      }
    }

    foreach ($entity_types as $entity_type => $entity_type_label) {
      $form['entities'][$entity_type] = [
        '#type' => 'checkbox',
        '#title' => $entity_type_label,
        '#default_value' => FALSE,
        '#attributes' => [
          'class' => ['field-descriptions-list-entity-type']
        ]
      ];
    }

    // Fieldset for optional downloading of CSV file.
    $form['download'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Export'),
      '#attributes' => ['class' => ['fieldset-no-legend']],
    ];
    $form['download']['download_csv'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Export to CSV file'),
      '#default_value' => FALSE,
      '#description' => $this->t("Write output to file 'entity-type-descriptions.csv' in the project root folder."),
    ];

    // The wrapper for Ajax results list.
    $form['field_descriptions_list'] = [
      // Set the results to be below the form.
      '#weight' => 100,
      // The prefix/suffix are the div with the ID specified as the wrapper in
      // the submit button's #ajax definition.
      '#prefix' => '<div id="set_entity_descriptions_list_results_wrapper">',
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
      // Get the selected list of entity type IDs.
      $entities = array_keys(array_filter($form_state->getValue('entities')));

      // Saved for later write.
//    $event = $types['event'];
//    $event->set("description", "THis is a new description");
//    $event->save();
//
//    $entity_types = $this->entityTypeManager->getDefinitions();
//    $entity_type = $this->entityTypeManager->getDefinition('node');
//
//    $bundles = $this->entityTypeBundleInfo->getBundleInfo('node');
//    foreach($bundles as $bundle_id => $bundle) {
//      break;
//    }

      // Build the table header and rows.
      $header = $this->buildHeader();
      $rows = $this->buildRows($entities);

      // Form element that displays the result list.
      $form['field_descriptions_list']['result'] = [
        '#type' => 'markup',
        '#theme' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No fields are in use for the selected entity types.'),
      ];

      // Optionally download the CSV file.
      if ($form_state->getValues('download_csv')) {
        $this->downloadDataCSV($header, $rows);
      }
    }

    // The submit button.
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        // The ID of the <div/> into which search results should be inserted.
        'wrapper' => 'set_entity_descriptions_list_results_wrapper',
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
    // Return the results list element of the form.
    return $form['field_descriptions_list'];
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
   * Constructs the table header row.
   *
   * @return array
   *   The array of table headers.
   */
  public function buildHeader() {
    return [
      $this->t('Entity type'),
      $this->t('Bundle machine ID'),
      $this->t('Bundle label'),
      $this->t('Description')
    ];
  }

  /**
   * Lists all instances of fields on every fieldable entity.
   *
   * @param array $entity_types
   *   The array of entity type IDs to list bundle descriptions for, such as "node_type".
   *
   * @return array
   *   The array for the rows of descriptions, intended to be rendered as table rows.
   */
  public function buildRows(array $entity_types): array {
    $rows = [];

    // The entity types are selected by the user via the form.
    foreach($entity_types as $entity_type) {
      /* @var \Drupal\Core\Entity\EntityTypeInterface $definition */
      $definition = $this->entityTypeManager->getDefinition($entity_type);

      $bundles = $this->entityTypeManager->getStorage($entity_type)->loadMultiple();
      foreach ($bundles as $bundle) {
        $rows[] = [
          'data' => [
            $definition->getLabel()->render(), $bundle->id(), $bundle->label(), $bundle->getDescription(),
          ],
        ];
      }
    }

    return $rows;
  }

  /**
   * Outputs a header and table rows into a CSV file.
   *
   * @param array $header
   *   The table header.
   * @param array $rows
   *   The table rows.
   */
  private function downloadDataCSV(array $header, array $rows) {
    // Output into a csv file
    $fname = 'entity-type-descriptions.csv';
    $csv_file = fopen($fname, 'w') or die($this->t("CSV file '%fname' not be opened", ['%fname' => $fname]));

    // Write the header.
    fputcsv($csv_file, $header);

    // Write the rows.
    foreach($rows as $offset => $row) {
      fputcsv($csv_file, $row['data'], ',', '"');
    }

    fclose($csv_file);
  }

}
