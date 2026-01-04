jQuery(document).ready(function () {
    nicheToggle();
    fetch_posts();

});

function nicheToggle() {
    jQuery('.niche-toggle').click(function (e) {
        e.preventDefault();
        jQuery(this).parent().find('.niche-term').show();
        jQuery(this).hide();
    });
}


// 2. Listen for filter changes
jQuery('#my-cat-filter').on('change', function () {
    var category_slug = $(this).val();
    fetch_posts(category_slug);
});

function fetch_posts(category = '') {
    var container = $('#my-loop-grid-container');

    // Add loading opacity
    container.css('opacity', '0.5');

    jQuery.ajax({
        url: '/wp-admin/admin-ajax.php', // Or use localized variable
        type: 'POST',
        data: {
            action: 'my_custom_loop_filter', // Matches PHP action
            category: category,
            // nonce: '...' // Recommended for security
        },
        success: function (response) {
            if (response.success) {
                container.html(response.data);
            } else {
                container.html('<p>No posts found.</p>');
            }
            container.css('opacity', '1');
        }
    });
}