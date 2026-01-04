jQuery(document).ready(function () {
    nicheToggle();
});

function nicheToggle() {
    jQuery('.niche-toggle').click(function (e) {
        e.preventDefault();
        jQuery(this).parent().find('.niche-term').show();
        jQuery(this).hide();
    });
}