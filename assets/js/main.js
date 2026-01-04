jQuery(document).ready(function () {
    nicheToggle();
    fetch_posts();
    influencer_select_filters();

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

function influencer_select_filters() {

    // 1. Initialize all widgets independently
    document.querySelectorAll('.select-filter').forEach(widget => {

        // Scope elements to THIS specific widget instance
        const dropdownBtn = widget.querySelector('.dropdown-button');
        const dropdownMenu = widget.querySelector('.dropdown-menu');
        const checkboxes = widget.querySelectorAll('.dropdown-item input[type="checkbox"]');
        const tagsContainer = widget.querySelector('.tags-container');
        const resetBtn = widget.querySelector('.reset-btn');

        // Toggle Dropdown
        dropdownBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            // Close other open widgets (optional UX choice)
            closeAllOtherDropdowns(dropdownMenu, dropdownBtn);

            dropdownMenu.classList.toggle('show');
            dropdownBtn.classList.toggle('open');
        });

        // Handle Checkbox Selection
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                updateTags();
            });
        });

        // Reset functionality
        resetBtn.addEventListener('click', () => {
            checkboxes.forEach(box => box.checked = false);
            updateTags();
            tagsContainer.hide();
        });

        // Function to Render Tags
        function updateTags() {
            tagsContainer.innerHTML = ''; // Clear only this widget's container

            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    createTag(checkbox.dataset.label, checkbox);
                }
            });
        }

        // Create individual Tag
        function createTag(label, linkedCheckbox) {
            const tag = document.createElement('div');
            tag.classList.add('tag');

            const text = document.createElement('span');
            text.innerText = label;

            const closeBtn = document.createElement('span');
            closeBtn.classList.add('tag-close');
            closeBtn.innerHTML = '&times;';

            // Remove tag logic (Uncheck specific box in this widget)
            closeBtn.addEventListener('click', () => {
                linkedCheckbox.checked = false;
                updateTags();
            });

            tag.appendChild(text);
            tag.appendChild(closeBtn);
            tagsContainer.appendChild(tag);
            tagsContainer.show();
            
        }
    });

    // 2. Global "Click Outside" Listener
    document.addEventListener('click', (e) => {
        document.querySelectorAll('.select-filter').forEach(widget => {
            const dropdownBtn = widget.querySelector('.dropdown-button');
            const dropdownMenu = widget.querySelector('.dropdown-menu');

            if (!dropdownBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
                dropdownMenu.classList.remove('show');
                dropdownBtn.classList.remove('open');
            }
        });
    });

    // Helper: Close all widgets except the one currently clicked
    function closeAllOtherDropdowns(currentMenu, currentBtn) {
        document.querySelectorAll('.select-filter').forEach(widget => {
            const menu = widget.querySelector('.dropdown-menu');
            const btn = widget.querySelector('.dropdown-button');

            if (menu !== currentMenu && btn !== currentBtn) {
                menu.classList.remove('show');
                btn.classList.remove('open');
            }
        });
    }
}