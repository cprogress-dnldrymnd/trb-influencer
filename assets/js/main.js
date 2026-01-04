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
    var container = jQuery('#my-loop-grid-container');

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


// 1. Select all widgets on the page
const allWidgets = document.querySelectorAll('.filter-widget');

// 2. Loop through each widget and apply logic independently
allWidgets.forEach(widget => {

    // Scope elements to THIS specific widget only
    const dropdownBtn = widget.querySelector('.dropdown-button');
    const dropdownMenu = widget.querySelector('.dropdown-menu');
    const checkboxes = widget.querySelectorAll('input[type="checkbox"]');
    const tagsContainer = widget.querySelector('.tags-container');
    const resetBtn = widget.querySelector('.reset-btn');

    // Toggle Dropdown
    dropdownBtn.addEventListener('click', (e) => {
        e.stopPropagation();

        // Optional: Close all other open dropdowns first
        document.querySelectorAll('.dropdown-menu').forEach(menu => {
            if (menu !== dropdownMenu) menu.classList.remove('show');
        });
        document.querySelectorAll('.dropdown-button').forEach(btn => {
            if (btn !== dropdownBtn) btn.classList.remove('open');
        });

        dropdownMenu.classList.toggle('show');
        dropdownBtn.classList.toggle('open');
    });

    // Handle Checkbox Changes
    checkboxes.forEach(box => {
        box.addEventListener('change', () => {
            renderTags();
        });
    });

    // Reset Button
    resetBtn.addEventListener('click', () => {
        checkboxes.forEach(box => box.checked = false);
        renderTags();
    });

    // Render Tags Function (Scoped to this widget)
    function renderTags() {
        tagsContainer.innerHTML = ''; // Clear only this widget's tags

        checkboxes.forEach(box => {
            if (box.checked) {
                const tag = document.createElement('div');
                tag.classList.add('tag');

                const text = document.createElement('span');
                text.innerText = box.getAttribute('data-label');

                const closeBtn = document.createElement('span');
                closeBtn.classList.add('tag-close');
                closeBtn.innerHTML = '&times;';

                // Uncheck box when tag is closed
                closeBtn.addEventListener('click', () => {
                    box.checked = false;
                    renderTags();
                });

                tag.appendChild(text);
                tag.appendChild(closeBtn);
                tagsContainer.appendChild(tag);
            }
        });
    }
});

// 3. Global Click Listener to close ANY dropdown when clicking outside
document.addEventListener('click', (e) => {
    // Loop through all widgets to see if the click was outside of them
    allWidgets.forEach(widget => {
        const menu = widget.querySelector('.dropdown-menu');
        const btn = widget.querySelector('.dropdown-button');

        if (!widget.contains(e.target)) {
            menu.classList.remove('show');
            btn.classList.remove('open');
        }
    });
});