jQuery(function($) {
    var $table = $('#bmsm-tiers-table tbody');
    var template = $('#bmsm-tier-row-template').html();

    $('#bmsm-add-tier').on('click', function(e) {
        e.preventDefault();
        var index = Date.now();
        var row = template.replace(/__INDEX__/g, index);
        $table.append(row);
    });

    $(document).on('click', '.bmsm-remove-tier', function(e) {
        e.preventDefault();
        $(this).closest('tr').remove();
    });
});
