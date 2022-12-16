(function ($, Drupal) {
  Drupal.behaviors.checkall_entities = {
    attach: function (context) {
      $('.checkall-btn').on('click', function (e) {
        $('.field-descriptions-list-entity-type').prop('checked', this.checked);
      });
    }
  };

})(jQuery, Drupal);
