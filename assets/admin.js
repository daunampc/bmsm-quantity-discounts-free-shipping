jQuery(function($) {
    function addRow(buttonSelector, tableSelector, templateSelector) {
        $(buttonSelector).on('click', function(e) {
            e.preventDefault();
            var template = $(templateSelector).html();
            var index = Date.now();
            $(tableSelector + ' tbody').append(template.replace(/__INDEX__/g, index));
        });
    }

    addRow('#bmsm-add-tier', '#bmsm-tiers-table', '#bmsm-tier-row-template');
    addRow('#bmsm-add-offer', '#bmsm-offers-table', '#bmsm-offer-row-template');

    $(document).on('click', '.bmsm-remove-tier, .bmsm-remove-offer', function(e) {
        e.preventDefault();
        $(this).closest('tr').remove();
    });
});
