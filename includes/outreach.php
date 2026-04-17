<?php

/**
 * Plugin Name: DD Outreach Manager
 * Plugin URI: https://digitallydisruptive.co.uk/
 * Description: Manages Elementor form submissions for outreach, dispatches multiple dynamic HTML notifications, provides a master-detail dashboard, and handles dynamic credit costs via settings and shortcodes.
 * Version: 2.3.2
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly to prevent direct file execution.
}

/**
 * Class DD_Outreach_Manager
 * Handles Elementor form interception, dynamic HTML generation, master-detail dashboard,
 * and the advanced backend HTML Email Builder with repeater functionalities.
 */
class DD_Outreach_Manager
{

    /**
     * Initializes the class, registers hooks, shortcodes, and AJAX endpoints.
     * @return void
     */
    public function __construct()
    {
        // Global Styles
        add_action('wp_head', [$this, 'inject_global_styles']);

        // Legacy/Existing Form Functionality
        add_action('elementor_pro/forms/new_record', [$this, 'process_elementor_form_response'], 10, 2);
        add_action('wp_footer', [$this, 'inject_elementor_success_scripts']);

        // Dynamic Elementor Form Option Injection
        add_filter('elementor_pro/forms/render/item', [$this, 'inject_elementor_form_options'], 10, 3);

        // New Master-Detail Dashboard Functionality
        add_shortcode('dd_outreach_list', [$this, 'render_list_shortcode']);
        add_shortcode('dd_outreach_view', [$this, 'render_view_shortcode']);

        // New Shortcode for Dynamic Credit Cost
        add_shortcode('dd_outreach_credit_cost', [$this, 'render_credit_cost_shortcode']);

        // New Shortcode for Dynamic Outreach Message Preview
        add_shortcode('outreach_message', [$this, 'render_outreach_message_shortcode']);

        // Frontend AJAX Handlers for dynamic viewing & filtering
        add_action('wp_ajax_dd_get_outreach_details', [$this, 'ajax_get_outreach_details']);
        add_action('wp_ajax_dd_filter_outreach_list', [$this, 'ajax_filter_outreach_list']);

        // Frontend AJAX Handlers for Notes CRUD
        add_action('wp_ajax_dd_save_outreach_note', [$this, 'ajax_save_outreach_note']);
        add_action('wp_ajax_dd_delete_outreach_note', [$this, 'ajax_delete_outreach_note']);

        // Backend Admin Menu & Settings
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Backend AJAX Handler for Live Email Preview
        add_action('wp_ajax_dd_preview_email', [$this, 'ajax_preview_email']);

        // Enqueue necessary scripts for the interactive frontend dashboard
        add_action('wp_enqueue_scripts', [$this, 'enqueue_dashboard_scripts']);

        // Backend Meta Boxes
        add_action('add_meta_boxes', [$this, 'add_note_meta_box']);
    }

    /**
     * Registers the plugin settings submenu page under the 'outreach' custom post type.
     * Logically partitions components via a tabbed interface.
     *
     * @return void
     */
    public function register_admin_menu()
    {
        $parent_slug = post_type_exists('outreach') ? 'edit.php?post_type=outreach' : 'options-general.php';

        add_submenu_page(
            $parent_slug,
            'Outreach Settings',
            'Settings',
            'manage_options',
            'dd-outreach-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Registers the settings and database options for the Email Builder array, General Settings, and Form Builder options.
     *
     * @return void
     */
    public function register_settings()
    {
        register_setting('dd_outreach_settings_group', 'dd_outreach_email_templates');
        register_setting('dd_outreach_settings_group', 'dd_outreach_credit_cost', [
            'type' => 'integer',
            'default' => 1,
            'sanitize_callback' => 'absint'
        ]);

        // Default Message Setting
        register_setting('dd_outreach_settings_group', 'dd_outreach_default_message', [
            'type' => 'string',
            'default' => $this->get_default_outreach_message()
        ]);

        // Dynamic Elementor Form Options
        register_setting('dd_outreach_settings_group', 'dd_outreach_project_types');
        register_setting('dd_outreach_settings_group', 'dd_outreach_project_lengths');
    }

    /**
     * Helper to return the default Outreach Message template.
     * @return string
     */
    private function get_default_outreach_message()
    {
        return "Hi {influencer_name},\n\nI hope this message finds you well! My name is {sender_name}, and I represent {brand_name}. We’ve been following your incredible work and would love to explore a potential collaboration with you for our upcoming {project_type} campaign.\n\nHere’s a brief overview of the opportunity:\n\n{{fields}}\n\nWe believe your unique style and audience would be a great fit for this campaign, and we’re excited about the possibility of working together. Whether it’s promoting our new products, sharing your experience with our brand, or helping us amplify our message, we think you could bring something special to this collaboration.\n\nIf you're interested, we’d love to chat further and discuss the next steps, including any details, expectations, and how we can tailor the project to fit your personal style.\n\nLooking forward to hearing from you!\n\nKind regards,\n{sender_name}\n{job_title}\n{brand_name}";
    }

    /**
     * Renders the Dynamic Read-Only Message Preview via [outreach_message].
     * Populates a data-template attribute to prevent script-stripping issues inside Elementor HTML elements/Popups.
     */
    public function render_outreach_message_shortcode($atts)
    {
        if (!is_user_logged_in()) return '';

        $current_user = wp_get_current_user();
        $sender_name = $current_user->first_name && $current_user->last_name ? $current_user->first_name . ' ' . $current_user->last_name : $current_user->display_name;
        $brand_name = get_user_meta($current_user->ID, 'brand_name', true) ?: $sender_name;
        $job_title = get_user_meta($current_user->ID, 'job_title', true) ?: 'Representative';

        // Influencer context
        $influencer_id = get_the_ID();
        $influencer_name = get_the_title($influencer_id);

        $template = get_option('dd_outreach_default_message', $this->get_default_outreach_message());

        $replacements = [
            '{influencer_name}' => $influencer_name,
            '{sender_name}' => $sender_name,
            '{brand_name}' => $brand_name,
            '{job_title}' => $job_title,
        ];

        // Replace strictly static tags before sending to the frontend DOM
        $js_template = str_replace(array_keys($replacements), array_values($replacements), $template);
        $json_encoded_template = wp_json_encode($js_template);

        ob_start();
?>
        <div id="dd-outreach-message-preview" class="dd-message-content" data-template="<?php echo esc_attr($json_encoded_template); ?>" style="background:#fdfdfd; padding:15px; border:1px solid #000; border-radius:5px; margin-top:10px; font-size: 15px; line-height: 1.6; color: #000;">
            Loading message preview...
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Intercepts Elementor form rendering to dynamically inject dropdown options.
     * Targets specific field IDs ('project_type' and 'project_length') and replaces their predefined options.
     *
     * @param array  $item       The Elementor form field array.
     * @param int    $item_index The index position of the field.
     * @param object $form       The Elementor form instance.
     * @return array Modified field array containing backend-defined options.
     */
    public function inject_elementor_form_options($item, $item_index, $form)
    {
        if (isset($item['custom_id'])) {
            if ('project_type' === $item['custom_id']) {
                $dynamic_types = get_option('dd_outreach_project_types', '');
                if (!empty($dynamic_types)) {
                    $item['field_options'] = $dynamic_types;
                }
            } elseif ('project_length' === $item['custom_id']) {
                $dynamic_lengths = get_option('dd_outreach_project_lengths', '');
                if (!empty($dynamic_lengths)) {
                    $item['field_options'] = $dynamic_lengths;
                }
            }
        }

        return $item;
    }

    /**
     * Enqueues administration scripts and styles strictly for the settings page.
     * Handles live HTML preview rendering, merge tag injection, and Repeater Field logic.
     *
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_admin_scripts($hook)
    {
        if (strpos($hook, 'dd-outreach-settings') === false) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-sortable'); // Require sortable for drag-and-drop

        $custom_js = "
        jQuery(document).ready(function($) {
            
            // --- 1. Tab Switcher Logic ---
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.dd-tab-content').hide();
                var target = $(this).attr('href');
                $(target).show();
            });

            // --- 2. Repeater Field Logic (Duplicate, Delete, Reorder, Collapse) ---
            function reindexRepeater() {
                $('#dd-repeater-container .dd-repeater-item:not(.blueprint)').each(function(index) {
                    $(this).find('[name^=\"dd_outreach_email_templates\"]').each(function() {
                        var name = $(this).attr('name');
                        if (name) {
                            var newName = name.replace(/\[\d+\]/, '[' + index + ']');
                            $(this).attr('name', newName);
                        }
                    });
                    $(this).find('.template-counter').text(index + 1);
                });
            }

            // Initialize Sortable
            $('#dd-repeater-container').sortable({
                handle: '.drag-handle',
                axis: 'y',
                update: function(event, ui) {
                    reindexRepeater();
                }
            });

            // Add New Item
            $('#dd-add-template').on('click', function(e) {
                e.preventDefault();
                var clone = $('.dd-repeater-item.blueprint').clone(true).removeClass('blueprint').hide();
                clone.find('input, textarea').prop('disabled', false); // Enable fields in clone
                $('#dd-repeater-container').append(clone);
                clone.fadeIn(300);
                reindexRepeater();
            });

            // Duplicate Item
            $('#dd-repeater-container').on('click', '.duplicate-item', function(e) {
                e.preventDefault();
                var item = $(this).closest('.dd-repeater-item');
                var clone = item.clone(true);
                
                // Manually copy textarea values as .clone() misses them sometimes
                item.find('textarea').each(function(i) {
                    clone.find('textarea').eq(i).val($(this).val());
                });

                item.after(clone);
                clone.hide().fadeIn(300);
                reindexRepeater();
            });

            // Delete Item
            $('#dd-repeater-container').on('click', '.delete-item', function(e) {
                e.preventDefault();
                if (confirm('Are you sure you want to delete this template?')) {
                    $(this).closest('.dd-repeater-item').fadeOut(300, function() {
                        $(this).remove();
                        reindexRepeater();
                    });
                }
            });

            // Collapse Item
            $('#dd-repeater-container').on('click', '.collapse-item, .drag-handle', function(e) {
                e.preventDefault();
                var body = $(this).closest('.dd-repeater-item').find('.dd-repeater-body');
                body.slideToggle(200);
                var btn = $(this).closest('.dd-repeater-item').find('.collapse-item');
                btn.text(body.is(':visible') ? 'Collapse' : 'Expand');
            });


            // --- 3. Merge Tag Injection & Editor Tracking ---
            var lastFocusedElement = null;

            // Track the last focused input or textarea within the template container
            $('#dd-repeater-container').on('focus', 'input[type=\"text\"], textarea', function() {
                lastFocusedElement = this;
                
                // Immediately trigger preview for the active textarea if it's a body editor
                if ($(this).hasClass('dd-email-body-editor')) {
                    triggerPreviewUpdate($(this).val());
                }
            });

            $('.dd-merge-tag').on('click', function(e) {
                e.preventDefault();
                var tag = $(this).data('tag');
                
                if (lastFocusedElement) {
                    var txtarea = $(lastFocusedElement);
                    var val = txtarea.val();
                    var start = lastFocusedElement.selectionStart;
                    var end = lastFocusedElement.selectionEnd;
                    
                    txtarea.val(val.substring(0, start) + tag + val.substring(end));
                    
                    // If it was the body, trigger preview
                    if (txtarea.hasClass('dd-email-body-editor')) {
                        triggerPreviewUpdate(txtarea.val());
                    }
                    
                    // Refocus
                    txtarea.focus();
                    lastFocusedElement.selectionEnd = start + tag.length;
                } else {
                    alert('Please click inside a field (To, Subject, or Body) to insert a merge tag.');
                }
            });

            // --- 4. Live Preview AJAX logic ---
            var previewTimer;
            function triggerPreviewUpdate(contentToPreview) {
                if (!contentToPreview) return; // Ignore if undefined

                $.post(ajaxurl, {
                    action: 'dd_preview_email',
                    security: ddAdmin.nonce,
                    template: contentToPreview
                }, function(response) {
                    if (response.success) {
                        var iframe = document.getElementById('dd-email-preview-iframe');
                        var doc = iframe.contentWindow.document;
                        doc.open();
                        doc.write(response.data);
                        doc.close();
                    }
                });
            }

            // Update preview as the user types in ANY body textarea
            $('#dd-repeater-container').on('keyup change', '.dd-email-body-editor', function() {
                var content = $(this).val();
                clearTimeout(previewTimer);
                previewTimer = setTimeout(function() {
                    triggerPreviewUpdate(content);
                }, 500);
            });

            // Render the first active template on page load
            setTimeout(function() {
                var firstBody = $('#dd-repeater-container .dd-repeater-item:not(.blueprint) .dd-email-body-editor').first();
                if(firstBody.length) {
                    triggerPreviewUpdate(firstBody.val());
                }
            }, 300);

        });
        ";

        wp_add_inline_script('jquery-core', $custom_js);
        wp_localize_script('jquery-core', 'ddAdmin', [
            'nonce' => wp_create_nonce('dd_admin_nonce')
        ]);
    }

    /**
     * Enqueues the custom jQuery dashboard handler.
     * Incorporates explicit breakpoint handlers for < 1025px modal triggering.
     * * @return void
     */
    public function enqueue_dashboard_scripts()
    {
        wp_enqueue_script('jquery');

        $script = "
        jQuery(document).ready(function($) {
            
            // --- 1. Master-Detail List Click Loader ---
            function bindListItemClicks() {
                $('.dd-outreach-item').off('click').on('click', function(e) {
                    
                    // Determine if triggered by physical click vs script dispatch
                    var isHumanClick = e.originalEvent !== undefined;
                    var postId = $(this).data('post-id');
                    var container = $('#dd-outreach-view-container');
                    
                    // Responsive Check: If < 1025px, avoid auto-loading the first element immediately
                    if ($(window).width() < 1025 && !isHumanClick) {
                        return; // Halt execution to prevent the modal from popping open instantly on load
                    }

                    $('.dd-outreach-item').removeClass('active-item');
                    $(this).addClass('active-item');

                    // Activate modal overlay lock strictly on devices < 1025px
                    if ($(window).width() < 1025) {
                        container.addClass('dd-modal-active');
                        $('body').css('overflow', 'hidden'); // Lock background scroll
                    }

                    container.html('<div class=\"dd-modal-content-wrapper\"><span class=\"dd-view-placeholder\">Loading...</span></div>');

                    $.ajax({
                        url: ddOutreach.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'dd_get_outreach_details',
                            security: ddOutreach.nonce,
                            post_id: postId
                        },
                        success: function(response) {
                            if(response.success) {
                                container.html(response.data);
                            } else {
                                container.html('<div class=\"dd-modal-content-wrapper\">' + (response.data || '<span class=\"dd-view-error\">Error loading details.</span>') + '</div>');
                            }
                        }
                    });
                });
            }

            // Bind list clicks on initial load and click first item
            bindListItemClicks();
            var firstItem = $('.dd-outreach-item').first();
            if (firstItem.length) {
                firstItem.trigger('click');
            } else {
                // Display notice on placeholder if no items are found on initial load
                $('#dd-outreach-view-container').html('<span class=\"dd-view-placeholder\">No outreach found.</span>');
                $('#no-outreach-found').removeClass('hide-element');
                $('#outreach-found').addClass('hide-element');
            }

            // --- Modal Destruction Handlers (< 1025px) ---
            $(document).on('click', '#dd-close-modal', function(e) {
                e.preventDefault();
                $('#dd-outreach-view-container').removeClass('dd-modal-active');
                $('body').css('overflow', '');
            });

            // Dismiss modal when clicking outside the container content bounds
            $(document).on('click', '#dd-outreach-view-container', function(e) {
                if ($(window).width() < 1025 && e.target === this) {
                    $(this).removeClass('dd-modal-active');
                    $('body').css('overflow', '');
                }
            });


            // --- 2. Filtering Logic ---
            var filterTimer;
            
            function triggerFilter() {
                var searchQuery = $('#dd-outreach-search').val();
                
                // Collect project types
                var selectedTypes = [];
                $('input[name=\"project_type[]\"]:checked').each(function() {
                    var val = $(this).attr('data-label') || $(this).val();
                    selectedTypes.push(val);
                });
                if (selectedTypes.length === 0 && $('select[name=\"project_type\"]').length > 0 && $('select[name=\"project_type\"]').val() !== '') {
                    selectedTypes.push($('select[name=\"project_type\"]').val());
                }

                // Collect project lengths
                var selectedLengths = [];
                $('input[name=\"project_length[]\"]:checked').each(function() {
                    var val = $(this).attr('data-label') || $(this).val();
                    selectedLengths.push(val);
                });
                if (selectedLengths.length === 0 && $('select[name=\"project_length\"]').length > 0 && $('select[name=\"project_length\"]').val() !== '') {
                    selectedLengths.push($('select[name=\"project_length\"]').val());
                }

                $('#dd-outreach-list-container').html('<p style=\"padding: 20px; text-align:center;\">Loading...</p>');

                $.ajax({
                    url: ddOutreach.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dd_filter_outreach_list',
                        security: ddOutreach.nonce,
                        search: searchQuery,
                        project_type: selectedTypes,
                        project_length: selectedLengths
                    },
                    success: function(response) {
                        if(response.success) {
                            $('#dd-outreach-list-container').html(response.data);
                            bindListItemClicks(); 
                            
                            var newFirstItem = $('.dd-outreach-item').first();
                            if (newFirstItem.length) {
                                newFirstItem.trigger('click');
                            } else {
                                // Display notice on placeholder if no filter results are found
                                $('#dd-outreach-view-container').html('<span class=\"dd-view-placeholder\">No outreach found matching your criteria.</span>');
                            }
                        }
                    }
                });
            }

            $('#dd-outreach-search').on('keyup', function() {
                clearTimeout(filterTimer);
                filterTimer = setTimeout(triggerFilter, 500);
            });

            $(document).on('change', 'input[name=\"project_type[]\"], select[name=\"project_type\"], input[name=\"project_length[]\"], select[name=\"project_length\"]', function() {
                triggerFilter();
            });

            // Reset button: clear all filter inputs and re-run the list query
            $(document).on('click', '.reset-btn, .tag-close', function(e) {
                e.preventDefault();
                triggerFilter();
            });


            // --- 3. Note CRUD Event Delegation ---
            var viewContainer = $('#dd-outreach-view-container');

            // Save / Update Action
            viewContainer.on('click', '#dd-save-note', function(e) {
                e.preventDefault();
                var btn = $(this);
                var postId = btn.data('post-id');
                var noteId = $('#dd-note-input-id').val();
                var title = $('#dd-note-input-title').val();
                var content = $('#dd-note-input-content').val();

                if (!content.trim()) {
                    alert('Please enter note content before saving.');
                    return;
                }

                btn.text('SAVING...').prop('disabled', true);

                $.ajax({
                    url: ddOutreach.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dd_save_outreach_note',
                        security: ddOutreach.nonce,
                        post_id: postId,
                        note_id: noteId,
                        note_title: title,
                        note_content: content
                    },
                    success: function(res) {
                        btn.text('💾 SAVE NOTE').prop('disabled', false);
                        if(res.success) {
                            // Inject the freshly built list of notes into the wrapper
                            $('#dd-notes-list-wrapper').html(res.data);
                            
                            // Reset form back to \"Create\" state
                            $('#dd-cancel-edit-note').trigger('click');
                        } else {
                            alert('An error occurred while saving the note.');
                        }
                    }
                });
            });

            // Edit Action (Populate Form)
            viewContainer.on('click', '.dd-edit-note', function(e) {
                e.preventDefault();
                var card = $(this).closest('.dd-steps-card');
                var noteId = $(this).data('note-id');
                var currentTitle = card.find('.dd-display-note-title').text();
                var currentContent = card.find('.dd-raw-note-content').val();
                
                $('#dd-note-input-id').val(noteId);
                $('#dd-note-input-title').val(currentTitle);
                $('#dd-note-input-content').val(currentContent);
                
                $('#dd-note-form-heading').text('✏️ Edit Note');
                $('#dd-cancel-edit-note').show();
                $('#dd-note-input-content').focus();
            });

            // Cancel Edit Action
            viewContainer.on('click', '#dd-cancel-edit-note', function(e) {
                e.preventDefault();
                $('#dd-note-input-id').val('');
                $('#dd-note-input-title').val('');
                $('#dd-note-input-content').val('');
                $('#dd-note-form-heading').text('🗒️ Create a note for this project');
                $(this).hide();
            });

            // Delete Action
            viewContainer.on('click', '.dd-delete-note', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to permanently delete this note?')) return;
                
                var btn = $(this);
                var postId = btn.data('post-id');
                var noteId = btn.data('note-id');

                btn.text('DELETING...').prop('disabled', true);

                $.ajax({
                    url: ddOutreach.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dd_delete_outreach_note',
                        security: ddOutreach.nonce,
                        post_id: postId,
                        note_id: noteId
                    },
                    success: function(res) {
                        if(res.success) {
                            // Inject updated notes list
                            $('#dd-notes-list-wrapper').html(res.data);
                            
                            // If they delete the note they were currently editing, reset the form
                            if ($('#dd-note-input-id').val() === noteId) {
                                $('#dd-cancel-edit-note').trigger('click');
                            }
                        }
                    }
                });
            });


            // --- 4. Dynamic Message Preview Logic (Elementor Popup Safe) ---
            function updateMessagePreview() {
                var previewDiv = $('#dd-outreach-message-preview');
                if (!previewDiv.length) return;

                var rawTemplateData = previewDiv.attr('data-template');
                if (!rawTemplateData) return;

                var rawTemplate = '';
                try {
                    rawTemplate = JSON.parse(rawTemplateData);
                } catch(e) {
                    return; // Fail gracefully if unable to parse json attribute
                }

                var projectType = $('[name=\"form_fields[project_type]\"]').val() || 'N/A';
                var projectLength = $('[name=\"form_fields[project_length]\"]').val() || 'N/A';
                var projectDates = $('[name=\"form_fields[project_dates]\"]').val() || 'Flexible';
                var budgetRange = $('[name=\"form_fields[budget_range]\"]').val() || $('[name=\"form_fields[budget]\"]').val() || 'To be discussed';

                var tagStyle = 'background-color: #d1fae5; border: 1px solid #0f766e; color: #034146; padding: 6px 14px; border-radius: 999px; font-size: 13px; font-weight: 500; display: inline-block; margin: 2px;';

                var tagsHtml = '<div class=\"tags-container\">' +
                    '<div class=\"tag\" style=\"' + tagStyle + '\"><strong>Project type :</strong> ' + projectType + '</div>' +
                    '<div class=\"tag\" style=\"' + tagStyle + '\"><strong>Project length :</strong> ' + projectLength + '</div>' +
                    '<div class=\"tag\" style=\"' + tagStyle + '\"><strong>Project Dates :</strong> ' + projectDates + '</div>' +
                    '<div class=\"tag\" style=\"' + tagStyle + '\"><strong>Budget : </strong> ' + budgetRange + '</div>' +
                    '</div>';

                // Strip massive linebreaks surrounding the fields placeholder before injection
                var compiled = rawTemplate.replace(/[\\r\\n]*\\{\\{fields\\}\\}[\\r\\n]*/g, '<br><br>' + tagsHtml + '<br><br>');
                compiled = compiled.replace(/\\{project_type\\}/g, projectType);
                
                // Convert remaining organic line breaks
                compiled = compiled.replace(/(?:\\r\\n|\\r|\\n)/g, '<br>');

                previewDiv.html(compiled);
            }

            // Listen to form field changes
            $(document).on('change input', 'form.elementor-form select, form.elementor-form input', function() {
                updateMessagePreview();
            });

            // Fire preview calculation specifically when Elementor Popups are opened
            $(document).on('elementor/popup/show', function() {
                setTimeout(updateMessagePreview, 100);
            });

            // Initial fallback render
            setTimeout(updateMessagePreview, 300);

        });
        ";

        wp_register_script('dd-outreach-app', '', [], '', true);
        wp_enqueue_script('dd-outreach-app');
        wp_add_inline_script('dd-outreach-app', $script);

        wp_localize_script('dd-outreach-app', 'ddOutreach', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('dd_outreach_nonce')
        ]);
    }


    /**
     * Helper to return the default HTML boilerplate so it's not bloating the UI logic.
     * @return string
     */
    private function get_default_html_template()
    {
        return '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Partnership Opportunity</title>
</head>
<body style="margin:0;padding:20px;background-color:#EBEBEB;color:#1A1A1A;font-family:sans-serif;">
    <div style="background:#fff; max-width:600px; margin:0 auto; padding:30px; border-radius:8px;">
        <h2>{subject}</h2>
        <p>Hi {influencer_name},</p>
        <div>{message}</div>
        <br>
        <p>Best regards,<br>{sender_name}<br>{job_title} at {brand_name}</p>
        <p>Country: {country}</p>
        <img src="{avatar_url}" width="60" style="border-radius:50%;" />
        <br><br>
        <a href="mailto:{sender_email}">Reply to {brand_name}</a>
    </div>
</body>
</html>';
    }

    /**
     * Helper to retrieve default template structure for initialization
     * @return array
     */
    private function get_default_template_structure()
    {
        return [
            [
                'to'         => '{influencer_email}',
                'subject'    => 'A partnership opportunity with {brand_name}',
                'from_email' => '{sender_email}',
                'from_name'  => '{sender_name}',
                'reply_to'   => '{sender_email}',
                'cc'         => '',
                'bcc'        => '',
                'body'       => $this->get_default_html_template()
            ]
        ];
    }

    /**
     * Renders the Backend Settings Page HTML.
     * Contains the tabbed logic, Form Builder inputs, and the Repeater Email Builder.
     *
     * @return void
     */
    public function render_settings_page()
    {
        $templates = get_option('dd_outreach_email_templates', $this->get_default_template_structure());
        $credit_cost = get_option('dd_outreach_credit_cost', 1);

        // Safety catch: if somehow empty, force default structure
        if (!is_array($templates) || empty($templates)) {
            $templates = $this->get_default_template_structure();
        }
    ?>
        <style>
            /* Repeater UI CSS */
            .dd-repeater-item {
                border: 1px solid #c3c4c7;
                background: #fff;
                margin-bottom: 15px;
                border-radius: 4px;
                box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
            }

            .dd-repeater-header {
                padding: 10px 15px;
                background: #f6f7f7;
                border-bottom: 1px solid #c3c4c7;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }

            .dd-repeater-header h4 {
                margin: 0;
                font-size: 14px;
                flex-grow: 1;
                padding-left: 10px;
                cursor: pointer;
            }

            .dd-repeater-header .drag-handle {
                cursor: grab;
                color: #8c8f94;
            }

            .dd-repeater-header .actions a {
                margin-left: 10px;
                text-decoration: none;
                font-size: 13px;
            }

            .dd-repeater-header .actions a.delete-item {
                color: #d63638;
            }

            .dd-repeater-body {
                padding: 15px;
            }

            .dd-field-group {
                margin-bottom: 15px;
                display: flex;
                gap: 15px;
                flex-wrap: wrap;
            }

            .dd-field-group .field {
                flex: 1;
                min-width: 250px;
            }

            .dd-field-group label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
                font-size: 12px;
            }

            .dd-field-group input {
                width: 100%;
            }

            .blueprint {
                display: none;
            }
        </style>

        <div class="wrap">
            <h1>Outreach Manager Settings</h1>

            <h2 class="nav-tab-wrapper">
                <a href="#tab-general" class="nav-tab nav-tab-active">General Settings</a>
                <a href="#tab-form-builder" class="nav-tab">Form Builder</a>
                <a href="#tab-email-builder" class="nav-tab">Email Builder</a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields('dd_outreach_settings_group'); ?>

                <div id="tab-general" class="dd-tab-content" style="margin-top:20px;">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="dd_outreach_credit_cost">Outreach Credit Cost</label></th>
                            <td>
                                <input name="dd_outreach_credit_cost" type="number" id="dd_outreach_credit_cost" value="<?php echo esc_attr($credit_cost); ?>" class="regular-text" min="0">
                                <p class="description">Define the number of credits/points to deduct when a user successfully submits an outreach form.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dd_outreach_default_message">Default Outreach Message</label></th>
                            <td>
                                <textarea name="dd_outreach_default_message" id="dd_outreach_default_message" rows="15" class="large-text"><?php echo esc_textarea(get_option('dd_outreach_default_message', $this->get_default_outreach_message())); ?></textarea>
                                <p class="description">This establishes the core template shown to users in the <code>[outreach_message]</code> shortcode and is dispatched into the dashboard notes. Use placeholders: <code>{influencer_name}</code>, <code>{sender_name}</code>, <code>{brand_name}</code>, <code>{job_title}</code>, <code>{project_type}</code>. Place <code>{{fields}}</code> exactly where the dynamic tags container should render.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Settings'); ?>
                </div>

                <div id="tab-form-builder" class="dd-tab-content" style="margin-top:20px; display:none;">
                    <h3>Dynamic Form Options</h3>
                    <p>Define the options that will be dynamically injected into your Elementor forms and frontend filters. Enter one option per line. You may utilize Elementor's <code>value|Label</code> syntax.</p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="dd_outreach_project_types">Project Types Options</label></th>
                            <td>
                                <textarea name="dd_outreach_project_types" id="dd_outreach_project_types" rows="5" class="large-text"><?php echo esc_textarea(get_option('dd_outreach_project_types', '')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dd_outreach_project_lengths">Project Lengths Options</label></th>
                            <td>
                                <textarea name="dd_outreach_project_lengths" id="dd_outreach_project_lengths" rows="5" class="large-text"><?php echo esc_textarea(get_option('dd_outreach_project_lengths', '')); ?></textarea>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Settings'); ?>
                </div>

                <div id="tab-email-builder" class="dd-tab-content" style="margin-top:20px; display:none;">
                    <div style="display: flex; gap: 30px; align-items: flex-start; flex-wrap: wrap;">

                        <div style="flex: 1.5; min-width: 500px;">
                            <h3>Email Notification Templates</h3>
                            <p>Add multiple templates. All active templates in this list will be dispatched simultaneously when an outreach is submitted.</p>

                            <div style="margin-bottom: 15px; background: #fff; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                                <strong>Global Merge Tags (Click field to focus, then click tag to insert):</strong><br>
                                <?php
                                $tags = ['{influencer_email}', '{influencer_name}', '{brand_name}', '{sender_name}', '{sender_email}', '{job_title}', '{country}', '{avatar_url}', '{project_type}', '{project_length}', '{project_dates}', '{budget}', '{message}', '{subject}'];
                                foreach ($tags as $tag) {
                                    echo '<button type="button" class="button button-small dd-merge-tag" data-tag="' . esc_attr($tag) . '" style="margin: 2px;">' . esc_html($tag) . '</button>';
                                }
                                ?>
                            </div>

                            <div id="dd-repeater-container">
                                <?php foreach ($templates as $index => $tpl) : ?>
                                    <div class="dd-repeater-item">
                                        <div class="dd-repeater-header">
                                            <span class="dashicons dashicons-menu drag-handle"></span>
                                            <h4>Notification Template <span class="template-counter"><?php echo $index + 1; ?></span></h4>
                                            <div class="actions">
                                                <a href="#" class="collapse-item">Collapse</a>
                                                <a href="#" class="duplicate-item">Duplicate</a>
                                                <a href="#" class="delete-item">Delete</a>
                                            </div>
                                        </div>
                                        <div class="dd-repeater-body">

                                            <div class="dd-field-group">
                                                <div class="field">
                                                    <label>To (Recipient)</label>
                                                    <input type="text" name="dd_outreach_email_templates[<?php echo $index; ?>][to]" value="<?php echo esc_attr($tpl['to']); ?>" placeholder="{influencer_email}">
                                                </div>
                                                <div class="field">
                                                    <label>Subject Line</label>
                                                    <input type="text" name="dd_outreach_email_templates[<?php echo $index; ?>][subject]" value="<?php echo esc_attr($tpl['subject']); ?>">
                                                </div>
                                            </div>

                                            <div class="dd-field-group">
                                                <div class="field">
                                                    <label>From Name</label>
                                                    <input type="text" name="dd_outreach_email_templates[<?php echo $index; ?>][from_name]" value="<?php echo esc_attr($tpl['from_name']); ?>" placeholder="{sender_name}">
                                                </div>
                                                <div class="field">
                                                    <label>From Email</label>
                                                    <input type="text" name="dd_outreach_email_templates[<?php echo $index; ?>][from_email]" value="<?php echo esc_attr($tpl['from_email']); ?>" placeholder="{sender_email}">
                                                </div>
                                                <div class="field">
                                                    <label>Reply-To</label>
                                                    <input type="text" name="dd_outreach_email_templates[<?php echo $index; ?>][reply_to]" value="<?php echo esc_attr($tpl['reply_to'] ?? ''); ?>" placeholder="{sender_email}">
                                                </div>
                                            </div>

                                            <div class="dd-field-group">
                                                <div class="field">
                                                    <label>CC</label>
                                                    <input type="text" name="dd_outreach_email_templates[<?php echo $index; ?>][cc]" value="<?php echo esc_attr($tpl['cc'] ?? ''); ?>" placeholder="Optional (Comma separated)">
                                                </div>
                                                <div class="field">
                                                    <label>BCC</label>
                                                    <input type="text" name="dd_outreach_email_templates[<?php echo $index; ?>][bcc]" value="<?php echo esc_attr($tpl['bcc'] ?? ''); ?>" placeholder="Optional (Comma separated)">
                                                </div>
                                            </div>

                                            <div class="dd-field-group">
                                                <div class="field" style="width: 100%;">
                                                    <label>Email HTML Body (Click here to Live Preview)</label>
                                                    <textarea name="dd_outreach_email_templates[<?php echo $index; ?>][body]" class="dd-email-body-editor" style="width: 100%; height: 400px; font-family: monospace; font-size: 13px; line-height: 1.5; padding: 15px; border-radius: 4px; border: 1px solid #8c8f94;" dir="ltr"><?php echo esc_textarea($tpl['body']); ?></textarea>
                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <div class="dd-repeater-item blueprint">
                                    <div class="dd-repeater-header">
                                        <span class="dashicons dashicons-menu drag-handle"></span>
                                        <h4>New Template <span class="template-counter"></span></h4>
                                        <div class="actions">
                                            <a href="#" class="collapse-item">Collapse</a>
                                            <a href="#" class="duplicate-item">Duplicate</a>
                                            <a href="#" class="delete-item">Delete</a>
                                        </div>
                                    </div>
                                    <div class="dd-repeater-body">
                                        <div class="dd-field-group">
                                            <div class="field"><label>To (Recipient)</label><input type="text" name="dd_outreach_email_templates[999][to]" value="{influencer_email}" disabled></div>
                                            <div class="field"><label>Subject Line</label><input type="text" name="dd_outreach_email_templates[999][subject]" value="A partnership opportunity with {brand_name}" disabled></div>
                                        </div>
                                        <div class="dd-field-group">
                                            <div class="field"><label>From Name</label><input type="text" name="dd_outreach_email_templates[999][from_name]" value="{sender_name}" disabled></div>
                                            <div class="field"><label>From Email</label><input type="text" name="dd_outreach_email_templates[999][from_email]" value="{sender_email}" disabled></div>
                                            <div class="field"><label>Reply-To</label><input type="text" name="dd_outreach_email_templates[999][reply_to]" value="{sender_email}" disabled></div>
                                        </div>
                                        <div class="dd-field-group">
                                            <div class="field"><label>CC</label><input type="text" name="dd_outreach_email_templates[999][cc]" value="" disabled></div>
                                            <div class="field"><label>BCC</label><input type="text" name="dd_outreach_email_templates[999][bcc]" value="" disabled></div>
                                        </div>
                                        <div class="dd-field-group">
                                            <div class="field" style="width: 100%;">
                                                <label>Email HTML Body</label>
                                                <textarea name="dd_outreach_email_templates[999][body]" class="dd-email-body-editor" style="width: 100%; height: 400px; font-family: monospace; font-size: 13px; line-height: 1.5; padding: 15px; border-radius: 4px; border: 1px solid #8c8f94;" disabled dir="ltr"><?php echo esc_textarea($this->get_default_html_template()); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <button type="button" id="dd-add-template" class="button button-secondary"> + Add Another Email Template</button>

                            <hr style="margin: 20px 0;">
                            <?php submit_button('Save All Templates', 'primary', 'submit', false); ?>
                        </div>

                        <div style="flex: 1; min-width: 400px; position: sticky; top: 40px;">
                            <h3>Live Frame Preview</h3>
                            <p>Reflects the currently focused HTML body textarea utilizing the most recent database outreach (if available).</p>
                            <div style="border: 1px solid #ccc; border-radius: 8px; overflow: hidden; background: #EBEBEB; padding: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                                <iframe id="dd-email-preview-iframe" style="width: 100%; height: 750px; border: none;" src="about:blank"></iframe>
                            </div>
                        </div>

                    </div>
                </div>
            </form>
        </div>
    <?php
    }

    /**
     * AJAX endpoint to render the live HTML preview of the active editor.
     * Parses the focused textarea content and injects real database data.
     *
     * @return void
     */
    public function ajax_preview_email()
    {
        check_ajax_referer('dd_admin_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $template = isset($_POST['template']) ? wp_unslash($_POST['template']) : '';

        // 1. Establish dummy fallback data
        $preview_data = [
            'influencer_email' => 'creator@example.com',
            'influencer_name' => 'Cory Ruth',
            'brand_name'      => 'Acme Health Co.',
            'sender_name'     => 'Jane Doe',
            'job_title'       => 'Partnerships Director',
            'country_display' => $this->get_country_display('US'),
            'avatar_url'      => 'https://via.placeholder.com/60x60',
            'project_type'    => 'Affiliate partnership',
            'project_length'  => 'Ongoing / long-term',
            'project_dates'   => 'Flexible',
            'budget'          => '$1,000 - $5,000',
            'message'         => wp_kses_post(wpautop("We came across your profile and absolutely love your approach to women's health. We are planning a campaign and think your content feels like a strong fit.\n\nWe would love to explore a potential collaboration with you.")),
            'subject'         => 'Partnership Inquiry',
            'sender_email'    => 'outreach@acmehealth.com'
        ];

        // 2. Query the latest outreach post
        $latest_outreach = get_posts([
            'post_type'      => 'outreach',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => 'publish'
        ]);

        // 3. If an outreach post exists, overwrite dummy data with real data
        if (!empty($latest_outreach)) {
            $post      = $latest_outreach[0];
            $post_id   = $post->ID;
            $author_id = $post->post_author;

            // Extract Sender (Brand) Identity
            $sender      = get_userdata($author_id);
            $sender_name = $sender ? ($sender->first_name && $sender->last_name ? $sender->first_name . ' ' . $sender->last_name : $sender->display_name) : 'Unknown Sender';

            $meta_job_title = get_user_meta($author_id, 'job_title', true);
            $job_title      = !empty($meta_job_title) ? esc_html($meta_job_title) : 'Representative';

            $meta_brand_name = get_user_meta($author_id, 'brand_name', true);
            $brand_name      = !empty($meta_brand_name) ? esc_html($meta_brand_name) : esc_html($sender_name);

            $meta_country    = get_user_meta($author_id, 'country', true);
            $country_display = $this->get_country_display($meta_country);

            // Extract PMPro User Avatar explicitly via absolute url path
            $avatar_meta = get_user_meta($author_id, 'user_avatar', true);
            $avatar_url  = 'https://via.placeholder.com/60x60'; // fallback
            if (!empty($avatar_meta) && is_array($avatar_meta) && !empty($avatar_meta['fullurl'])) {
                $avatar_url = $avatar_meta['fullurl'];
            }

            // Extract Influencer & Project Scope
            $influencer_id    = get_post_meta($post_id, 'influencer_id', true);
            $influencer_name  = $influencer_id ? get_the_title($influencer_id) : 'Unknown Creator';
            $influencer_email = $influencer_id ? get_post_meta($influencer_id, 'creator_contact_emails', true) : 'creator@example.com';

            $project_type   = get_post_meta($post_id, 'project_type', true) ?: 'N/A';
            $project_length = get_post_meta($post_id, 'project_length', true) ?: 'Ongoing';
            $project_dates  = get_post_meta($post_id, 'project_dates', true) ?: 'Flexible';
            $budget         = get_post_meta($post_id, 'budget', true) ?: 'To be discussed';
            $message        = get_post_meta($post_id, 'message', true) ?: 'No message provided.';
            $subject        = get_the_title($post_id);

            // Populate the array with the live data
            $preview_data = [
                'influencer_email' => esc_html($influencer_email),
                'influencer_name' => esc_html($influencer_name),
                'brand_name'      => esc_html($brand_name),
                'sender_name'     => esc_html($sender_name),
                'job_title'       => esc_html($job_title),
                'country_display' => $country_display,
                'avatar_url'      => esc_url($avatar_url),
                'project_type'    => esc_html($project_type),
                'project_length'  => esc_html($project_length),
                'project_dates'   => esc_html($project_dates),
                'budget'          => esc_html($budget),
                'message'         => wp_kses_post(wpautop($message)),
                'subject'         => esc_html($subject),
                'sender_email'    => $sender ? $sender->user_email : 'no-reply@example.com'
            ];
        }

        // 4. Map the exact Merge Tags to the data array
        $dictionary = [
            '{influencer_email}' => $preview_data['influencer_email'],
            '{influencer_name}' => $preview_data['influencer_name'],
            '{brand_name}'      => $preview_data['brand_name'],
            '{sender_name}'     => $preview_data['sender_name'],
            '{job_title}'       => $preview_data['job_title'],
            '{country}'         => $preview_data['country_display'],
            '{avatar_url}'      => $preview_data['avatar_url'],
            '{project_type}'    => $preview_data['project_type'],
            '{project_length}'  => $preview_data['project_length'],
            '{project_dates}'   => $preview_data['project_dates'],
            '{budget}'          => $preview_data['budget'],
            '{message}'         => $preview_data['message'],
            '{subject}'         => $preview_data['subject'],
            '{sender_email}'    => $preview_data['sender_email'],
        ];

        // 5. Execute Merge Tag Search & Replace over the raw HTML
        $final_html = str_replace(array_keys($dictionary), array_values($dictionary), $template);

        wp_send_json_success($final_html);
    }

    /**
     * Outputs consolidated CSS into the document <head>.
     * Responsive modal overlay logic is introduced at max-width: 1024px.
     */
    public function inject_global_styles()
    {
    ?>
        <style>
            /* --- Original Elementor Form Summary Styles --- */
            .dd-message-overview {
                display: flex;
                justify-content: space-between;
                flex-wrap: wrap;
                font-size: 16px;
                font-weight: 500;
            }

            .dd-message-overview-container {
                font-family: inherit;
                margin-top: 15px;
                border-radius: 10px;
                border: 2px solid #034146;
                background-color: #fff;
            }

            .mt-0 {
                margin-top: 0 !important;
            }

            .dd-profile-header {
                display: flex;
                align-items: center;
                gap: 15px;
                padding: 15px 20px;
                border-bottom: 1px solid #E7E7E7;
            }

            .dd-avatar.dd-avatar.dd-avatar {
                width: 50px;
                height: 50px;
                border-radius: 50%;
            }

            .dd-profile-info {
                flex-grow: 1;
                line-height: 1.4;
            }

            .dd-message-sent-date {
                padding: 15px 20px;
                border-bottom: 1px solid #E7E7E7;
                font-size: 14px;
            }

            .dd-overview-header {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
                font-size: 14px;
                font-weight: bold;
            }

            .dd-overview-header .dd-timestamp {
                font-weight: normal;
                color: #555;
            }

            .dd-btn-outline {
                background-color: var(--e-global-color-1c4ea17);
                font-family: var(--e-global-typography-2a20fd0-font-family), Sans-serif;
                font-size: var(--e-global-typography-2a20fd0-font-size);
                font-weight: var(--e-global-typography-2a20fd0-font-weight);
                line-height: var(--e-global-typography-2a20fd0-line-height);
                letter-spacing: var(--e-global-typography-2a20fd0-letter-spacing);
                fill: var(--e-global-color-accent);
                color: var(--e-global-color-accent);
                border: 1px solid var(--e-global-color-accent);
                padding: 14px 23px 14px 23px;
                border-radius: 5px;
            }

            .dd-btn-outline:hover {
                background-color: var(--e-global-color-accent);
                color: var(--e-global-color-2ba2932);
            }

            .dd-message-overview-container .tags-container.tags-container.tags-container {
                margin: 0;
            }

            .tags-container.tags-container.tags-container br {
                display: none !important;
            }


            .dd-message-overview-container .tags-container.tags-container.tags-container .tag {
                gap: 4px;
            }

            .dd-subject-title {
                color: #034146;
                font-size: 18px !important;
                font-weight: bold;
                margin: 0;
                border-bottom: 1px solid #E7E7E7;
                font-family: Inter !important;
                padding: 15px 20px;
            }

            .dd-message-content {
                font-size: 15px;
                color: #000000;
                line-height: 1.6;
                padding: 15px 20px;
                font-family: Inter;
            }

            #dd-outreach-message-preview .tags-container {
                margin-top: 0;
            }

            #dd-outreach-message-preview .tags-container+br {
                display: none;
            }

            .dd-footer {
                display: flex;
                gap: 15px;
                margin-top: 15px;
            }

            .dd-footer a {
                font-family: var(--e-global-typography-2a20fd0-font-family), Sans-serif;
                font-size: 14px !important;
                font-weight: 600 !important;
                line-height: var(--e-global-typography-2a20fd0-line-height);
                letter-spacing: var(--e-global-typography-2a20fd0-letter-spacing);
            }

            .view-outreach a {
                background-color: var(--e-global-color-accent) !important;
                border: 1px solid var(--e-global-color-accent);
                color: var(--e-global-color-2ba2932) !important;
            }

            .view-outreach a:hover {
                background-color: var(--e-global-color-secondary) !important;
                border: 1px solid var(--e-global-color-secondary);
            }

            .close-outreach a {
                border-style: solid;
                border-color: var(--e-global-color-ee06e41) !important;
                background-color: transparent !important;
                color: var(--e-global-color-ee06e41) !important;
            }

            .close-outreach a:hover {
                background-color: var(--e-global-color-ee06e41) !important;
                color: var(--e-global-color-2ba2932) !important;
            }

            .submit-new a {
                border: none !important;
                background-color: transparent !important;
                color: var(--e-global-color-ee06e41) !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
            }

            .submit-new a span.elementor-button-text {
                text-decoration: underline;
            }

            /* --- Dashboard List Navigation Styles --- */
            .dd-dashboard-list-container {
                background: #fdfdfd;
                border: 1px solid #eaeaea;
                border-radius: 8px;
                width: 100%;
                max-width: 350px;
            }

            .outreach-filter {
                padding: 20px;
                border-bottom: 1px solid #BCBCBC;
            }

            .dd-filter-controls {
                margin-bottom: 20px;
            }

            .dd-list-search {
                width: 100%;
                margin-bottom: 15px;
                padding: 10px;
                border-radius: 4px;
                border: 1px solid #ccc;
                box-sizing: border-box;
            }

            .dd-filter-label-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            }

            .dd-filter-reset {
                font-size: 12px;
                color: #888;
                text-decoration: none;
            }

            .dd-filter-select {
                width: 100%;
                padding: 10px;
                border-radius: 4px;
                border: 1px solid #ccc;
                box-sizing: border-box;
            }

            .dd-filter-buttons-row {
                margin-top: 15px;
                display: flex;
                gap: 10px;
            }

            .dd-filter-btn {
                border-radius: 20px;
                border: 1px solid #ccc;
                background: transparent;
                padding: 5px 15px;
                cursor: pointer;
                font-size: 13px;
            }

            .dd-filter-btn.active {
                border-color: #4DB2A6;
                background: #E6F4F1;
                color: #4DB2A6;
            }

            .dd-item-list {
                max-height: 600px;
                overflow-y: auto;
            }

            .dd-outreach-item {
                display: flex;
                align-items: center;
                padding: 15px 20px;
                border-bottom: 1px solid #eee;
                border-top: 1px solid transparent;
                cursor: pointer;
                transition: background 0.2s;
            }

            .dd-outreach-item .avatar-holder {
                flex: 0 0 68px;
                width: 68px;
            }

            .dd-outreach-item .dd-item-content {
                flex: 0 0 calc(100% - 68px);
                width: calc(100% - 68px);
                padding-left: 10px;

            }

            .dd-outreach-item:hover,
            .dd-outreach-item.active-item {
                background: #FEF6F3;
                border-bottom: 1px solid #3B1527;
                border-top: 1px solid #3B1527;
            }

            .dd-item-avatar.dd-item-avatar.dd-item-avatar {
                width: 68px;
                height: 68px;
                border-radius: 50%;
                object-fit: cover;
                margin-right: 15px;
                border: 1px solid gray;
            }

            .dd-item-content {
                flex-grow: 1;
            }

            .dd-item-name {
                display: block;
                font-size: 15px;
                font-weight: 500;
                color: #000000;
            }

            .dd-item-handle {
                display: block;
                color: #000000;
                font-size: 14px;
                font-weight: 400;
            }

            .dd-item-title {
                display: block;
                font-size: 14px;
                color: #034146;
                font-weight: bold;
                margin-top: 4px;
                text-overflow: ellipsis;
                overflow: hidden;
                white-space: nowrap;
                max-width: 200px;
            }

            .dd-item-date {
                color: #8F8F8F;
                font-size: 13px;
            }

            /* --- Notes Component Styles --- */
            .dd-outreach-view-container {
                width: 100%;
                box-sizing: border-box;
            }

            /* --- Base Wrapper --- */
            .dd-modal-content-wrapper {
                width: 100%;
                position: relative;
            }

            .dd-close-modal {
                display: none;
                /* Hidden by default on desktop */
            }

            .dd-view-placeholder {
                text-align: center;
                color: #888;
                margin-top: 10%;
                display: block;
            }

            .dd-view-error {
                text-align: center;
                color: red;
                margin-top: 50%;
                transform: translateY(-50%);
                display: block;
            }

            .dd-notes-grid {
                display: flex;
                gap: 20px;
                flex-wrap: nowrap;
                align-items: flex-start;
                margin-top: 20px;
                font-family: Inter;
            }

            .dd-note-card {
                flex: 1;
                min-width: 280px;
                background: var(--e-global-color-2ba2932);
                border: 2px solid #FFE17B;
                border-radius: 8px;
                padding: 20px;
                box-sizing: border-box;
                position: sticky;
                top: 20px;
            }

            .dd-notes-list-container {
                flex: 1;
                min-width: 280px;
                display: flex;
                flex-direction: column;
                gap: 15px;
            }

            .dd-note-title.dd-note-title {
                margin-top: 0;
                font-size: 20px;
                margin-bottom: 15px;
                color: #3B1527;
                font-weight: bold;
            }

            .dd-note-desc {
                font-size: 14px;
                color: #8F8F8F;
                margin-bottom: 15px;
            }

            .dd-note-input {
                width: 100%;
                margin-bottom: 10px;
                padding: 10px;
                border: 1px solid #eee;
                border-radius: 4px;
                box-sizing: border-box;
                font-family: inherit;
            }

            .dd-note-textarea {
                width: 100%;
                height: 80px;
                padding: 10px;
                border: 1px solid #eee;
                border-radius: 4px;
                box-sizing: border-box;
                resize: vertical;
                font-family: inherit;
            }

            .dd-note-btn {
                margin-top: 10px;
                background: #ffcc00;
                border: none;
                padding: 10px 20px;
                border-radius: 4px;
                font-weight: bold;
                cursor: pointer;
                color: #333;
                font-size: 13px;
                transition: opacity 0.2s;
            }

            .dd-note-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            .dd-steps-content {
                background: #FCF3D5;
                padding: 15px;
                border-radius: 8px;
                font-size: 14px;
                color: #000;
                margin-bottom: 15px;
                line-height: 1.5;
                border: 1px solid #FFE17B;
            }

            .dd-steps-actions {
                display: flex;
                justify-content: flex-end;
                gap: 10px;
            }

            .dd-delete-btn {
                border: none;
                background: transparent;
                color: #aaa;
                cursor: pointer;
                font-size: 12px;
            }

            .dd-edit-btn {
                border: 1px solid #ddd;
                background: #f9f9f9;
                padding: 8px 15px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 12px;
                color: #333;
            }

            .dd-last-edited {
                text-align: right;
                font-size: 14px;
                color: #8F8F8F;
                margin-top: 10px !important;
                display: block;
            }

            .dd-no-notes {
                text-align: center;
                color: #888;
                padding: 20px;
                border: 1px dashed #ccc;
                border-radius: 8px;
                background-color: #fff;
            }

            .dd-note-btn.dd-note-btn.dd-note-btn {
                padding: 12px 20px !important;
                border: 1px solid #BCBCBC;
                font-family: Inter !important;
                font-size: 12px;
                font-weight: 600;
                letter-spacing: 0.6px;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .dd-note-btn.dd-note-btn.dd-note-btn.dd-note-btn-yellow {
                background-color: #FFE17B;
                border-color: #FFE17B;
                color: #000000;
            }

            .dd-note-btn.dd-note-btn.dd-note-btn.dd-note-btn-outline {
                background-color: transparent;
                color: #BCBCBC;
                border-color: #BCBCBC;
            }

            .dd-note-btn.dd-note-btn.dd-note-btn.dd-note-btn-outline span {
                text-decoration: underline;
            }

            .dd-note-btn.dd-note-btn.dd-note-btn.dd-note-btn-link {
                border: none;
                background-color: transparent;
            }

            .dd-note-btn.dd-note-btn.dd-note-btn.dd-note-btn-link span {
                text-decoration: underline;
            }

            /* --- Responsive Mobile Modal Interception --- */
            @media (max-width: 1024px) {
                .dd-outreach-view-container {
                    display: none;
                    /* Hide static desktop interface */
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100vw;
                    height: 100vh;
                    background: rgba(0, 0, 0, 0.6);
                    z-index: 999999;
                    padding: 20px;
                    box-sizing: border-box;
                    align-items: center;
                    justify-content: center;
                    backdrop-filter: blur(3px);
                }

                .dd-outreach-view-container.dd-modal-active {
                    display: flex;
                    /* Execute Modal Sequence */
                }

                .dd-modal-content-wrapper {
                    background: #fff;
                    width: 100%;
                    max-width: 600px;
                    max-height: 90vh;
                    overflow-y: auto;
                    border-radius: 8px;
                    padding: 50px 20px 20px;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
                }

                .dd-close-modal.dd-close-modal {
                    display: block;
                    /* Reveal responsive close trigger */
                    position: absolute;
                    top: 10px;
                    right: 15px;
                    background: none;
                    border: none;
                    font-size: 28px;
                    cursor: pointer;
                    color: #333;
                    z-index: 10;
                    line-height: 1;
                    background-color: transparent;
                    padding: 0;
                    border: none;
                }

                .dd-item-list {
                    max-height: 100%;
                }
            }

            @media(max-width: 1199px) {
                .dd-notes-grid {
                    flex-direction: column;
                }

                .dd-note-card {
                    width: 100%;
                }

                .dd-notes-list-container {
                    width: 100%;
                }

                .dd-dashboard-list-container {
                    max-width: 100%;
                }
            }
        </style>
    <?php
    }

    /**
     * Intercepts the Elementor Form submission to compile a custom HTML payload.
     * Generates a new 'outreach' post type entry, triggers email dispatch, and deducts dynamic credit cost.
     */
    public function process_elementor_form_response($record, $ajax_handler)
    {
        $form_id = $record->get_form_settings('form_id');

        if ('outreach_form' !== $form_id) {
            return;
        }

        $raw_fields = $record->get('fields');
        $data       = [];

        foreach ($raw_fields as $id => $field) {
            $data[$id] = $field['value'];
        }

        $current_user_id = get_current_user_id();
        $post_title = !empty($data['subject']) ? sanitize_text_field($data['subject']) : 'Outreach Submission - ' . current_time('Y-m-d H:i:s');

        // Dynamically compile the Message string relying on the server-side default template
        $sender = get_userdata($current_user_id);
        $sender_name = $sender && $sender->first_name && $sender->last_name ? $sender->first_name . ' ' . $sender->last_name : ($sender ? $sender->display_name : 'Representative');
        $brand_name = get_user_meta($current_user_id, 'brand_name', true) ?: $sender_name;
        $job_title = get_user_meta($current_user_id, 'job_title', true) ?: 'Representative';
        $influencer_name = get_the_title($data['influencer_id']);

        $message_template = get_option('dd_outreach_default_message', $this->get_default_outreach_message());

        // We build the tags HTML dynamically as it was requested to be inside {{fields}}
        $tag_style = 'background-color: #d1fae5; border: 1px solid #0f766e; color: #034146; padding: 6px 14px; border-radius: 999px; font-size: 13px; font-weight: 500; display: inline-block; margin: 2px;';

        $tags_html = '<div class="tags-container">
            <div class="tag" style="' . $tag_style . '"><strong>Project type :</strong> ' . esc_html($data['project_type'] ?? 'N/A') . '</div>
            <div class="tag" style="' . $tag_style . '"><strong>Project length :</strong> ' . esc_html($data['project_length'] ?? 'N/A') . '</div>
            <div class="tag" style="' . $tag_style . '"><strong>Project Dates :</strong> ' . esc_html($data['project_dates'] ?? 'Flexible') . '</div>
            <div class="tag" style="' . $tag_style . '"><strong>Budget : </strong> ' . esc_html($data['budget'] ?? $data['budget_range'] ?? 'To be discussed') . '</div>
        </div>';

        // Strip massive natural linebreaks surrounding the fields placeholder before injection
        $message_template = preg_replace('/[\r\n]*\{\{fields\}\}[\r\n]*/', '<br><br>{{fields}}<br><br>', $message_template);

        $replacements = [
            '{influencer_name}' => $influencer_name,
            '{sender_name}'     => $sender_name,
            '{brand_name}'      => $brand_name,
            '{job_title}'       => $job_title,
            '{project_type}'    => $data['project_type'] ?? 'N/A',
            '{{fields}}'        => $tags_html
        ];

        // Replace all placeholders and format into HTML
        $final_message = str_replace(array_keys($replacements), array_values($replacements), $message_template);

        // Finalize standard line break parsing
        $data['message'] = nl2br($final_message);

        $new_post_args = [
            'post_title'   => $post_title,
            'post_type'    => 'outreach',
            'post_status'  => 'publish',
            'post_author'  => $current_user_id,
            'post_content' => wp_kses_post($data['message']) . '<div class="hide-element">' . get_the_title($data['influencer_id']) . '</div>'
        ];

        $post_id = wp_insert_post($new_post_args);

        if (!is_wp_error($post_id)) {
            foreach ($data as $meta_key => $meta_value) {
                $sanitized_value = ('message' === $meta_key) ? wp_kses_post($meta_value) : sanitize_text_field($meta_value);
                update_post_meta($post_id, sanitize_key($meta_key), $sanitized_value);
            }

            // Dispatch HTML notification immediately after persisting state
            $this->send_outreach_email($data, $current_user_id);

            // Deduct Dynamic Points/Credits
            if (function_exists('mycred_subtract')) {
                $credit_cost = (int) get_option('dd_outreach_credit_cost', 1);

                if ($credit_cost > 0) {
                    /**
                     * Retrieve the targeted post's title and permalink utilizing the influencer_id.
                     * Formats the log entry as an HTML-linked string for point deduction tracking.
                     */
                    $target_post_id    = absint($data['influencer_id']);
                    $target_post_title = esc_html(get_the_title($target_post_id));
                    $target_post_url   = esc_url(get_permalink($target_post_id));

                    $dynamic_log_message = sprintf(
                        'Outreach form submission for "<a href="%s" target="_blank">%s</a>"',
                        $target_post_url,
                        $target_post_title
                    );

                    /**
                     * Executes point deduction utilizing the native myCred API.
                     * Explicitly sets the reference to 'outreach_submission'.
                     * * @param string $reference  The unique transaction reference.
                     * @param int    $user_id    The ID of the user losing points.
                     * @param float  $amount     The number of points to deduct.
                     * @param string $entry      The log entry message.
                     * @param int    $ref_id     (Optional) The related post ID for reference tracking.
                     */
                    mycred_subtract(
                        'outreach_submission',
                        $current_user_id,
                        $credit_cost,
                        wp_kses_post($dynamic_log_message),
                        $target_post_id
                    );
                }
            }

            if (function_exists('mycred_get_users_cred')) {
                $updated_points = mycred_get_users_cred($current_user_id);
                $ajax_handler->add_response_data('updated_points', $updated_points);
                // Also pass the cost back to the frontend logic so it updates UI accurately
                $ajax_handler->add_response_data('deducted_points', get_option('dd_outreach_credit_cost', 1));
            }

            $sent_date = get_the_date('g:i A, F jS Y', $post_id);
        } else {
            $sent_date = date_i18n(get_option('date_format'));
        }

        ob_start();
    ?>
        <div class="dd-message-overview">
            <span class="m-overview">Message overview</span>
            <span class="date">Sent at <?php echo esc_html($sent_date); ?></span>
        </div>
        <div class="dd-message-overview-container">
            <div class="dd-profile-header">
                <div class="avatar-holder">
                    <?= do_shortcode('[influencer_avatar post_id="' . esc_attr($data['influencer_id']) . '"]') ?>
                </div>
                <div class="dd-profile-info">
                    <strong><?php echo get_the_title($data['influencer_id']); ?></strong><br>
                    <small>@<?php echo do_shortcode('[instagram_id id="' . $data['influencer_id'] . '"]') ?></small>
                </div>
                <a href="<?= get_the_permalink() ?>" class="dd-btn-outline">VIEW CREATOR PROFILE</a>
            </div>
            <div class="dd-overview-body">
                <h3 class="dd-subject-title"><?php echo esc_html($data['subject'] ?? 'No Subject'); ?></h3>

                <div class="dd-message-content">
                    <?php echo wp_kses_post($data['message'] ?? 'No message provided.'); ?>
                </div>
            </div>
        </div>
        <div class="dd-footer">
            <div class="button-box view-outreach">
                <a class="elementor-button elementor-button-link elementor-size-sm" href="<?= get_the_permalink(8900) ?>">
                    <span class="elementor-button-content-wrapper">
                        <span class="elementor-button-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17.081" height="18.396" viewBox="0 0 17.081 18.396">
                                <g id="outreach_icon" data-name="outreach icon" transform="translate(-9.375 -6.25)">
                                    <path id="Path_139" data-name="Path 139" d="M40.076,38.442a.649.649,0,0,1,.414.414l.69,2.069,1.59-4.77L38,37.745l2.069.69Z" transform="translate(-22.607 -23.619)" fill="#fff"></path>
                                    <path id="Path_140" data-name="Path 140" d="M17.916,6.25a8.532,8.532,0,0,0-6.563,13.993l-1.281,3.521a.668.668,0,0,0,.151.69.66.66,0,0,0,.69.151l4.526-1.642a8.38,8.38,0,0,0,2.477.368,8.541,8.541,0,1,0,0-17.081Zm3.909,5.466L19.2,19.6a.657.657,0,0,1-.624.447.666.666,0,0,1-.624-.447L16.74,15.967l-3.633-1.209a.657.657,0,0,1-.447-.624.666.666,0,0,1,.447-.624l7.884-2.628a.647.647,0,0,1,.67.158.664.664,0,0,1,.158.67Z" fill="#fff"></path>
                                </g>
                            </svg>
                        </span>
                        <span class="elementor-button-text">VIEW IN OUTREACH</span>
                    </span>
                </a>
            </div>
            <div class="button-box close-outreach">
                <a class="elementor-button elementor-button-link elementor-size-sm" href="#">
                    <span class="elementor-button-content-wrapper">
                        <span class="elementor-button-text">CLOSE</span>
                    </span>
                </a>
            </div>
        </div>
    <?php
        $custom_html = ob_get_clean();
        $ajax_handler->add_response_data('dd_custom_html', $custom_html);
    }

    /**
     * Compiles and dispatches the HTML outreach email payload to the target influencer.
     * Iterates over all saved repeater templates and dynamically compiles their header/body merge tags.
     */
    private function send_outreach_email($data, $current_user_id)
    {
        $sender = get_userdata($current_user_id);
        if (!$sender) return false;

        $sender_name  = $sender->first_name && $sender->last_name ? $sender->first_name . ' ' . $sender->last_name : $sender->display_name;
        $sender_email = $sender->user_email;

        $meta_job_title = get_user_meta($current_user_id, 'job_title', true);
        $job_title      = !empty($meta_job_title) ? esc_html($meta_job_title) : 'Representative';

        $meta_brand_name = get_user_meta($current_user_id, 'brand_name', true);
        $brand_name      = !empty($meta_brand_name) ? esc_html($meta_brand_name) : esc_html($sender_name);

        $meta_country    = get_user_meta($current_user_id, 'country', true);
        $country_display = $this->get_country_display($meta_country);

        // Extract PMPro User Avatar explicitly via absolute url path
        $avatar_meta = get_user_meta($current_user_id, 'user_avatar', true);
        $avatar_url  = 'https://via.placeholder.com/60x60'; // Default safety fallback
        if (!empty($avatar_meta) && is_array($avatar_meta) && !empty($avatar_meta['fullurl'])) {
            $avatar_url = $avatar_meta['fullurl'];
        }

        // Resolve Influencer Context strictly via post meta
        $influencer_id   = absint($data['influencer_id']);
        $influencer_name = get_the_title($influencer_id);
        $influencer_email = get_post_meta($influencer_id, 'influencer_email', true);

        // Fallback constraint to post_author account email if no explicit meta is present
        if (empty($influencer_email) || !is_email($influencer_email)) {
            $influencer_post = get_post($influencer_id);
            if ($influencer_post) {
                $influencer_user = get_userdata($influencer_post->post_author);
                if ($influencer_user) {
                    $influencer_email = $influencer_user->user_email;
                }
            }
            // Abort if unable to establish recipient address
            if (empty($influencer_email)) return false;
        }

        // Compile Dictionary for Search/Replace execution
        $dictionary = [
            '{influencer_email}' => $influencer_email,
            '{influencer_name}' => $influencer_name,
            '{brand_name}'      => $brand_name,
            '{sender_name}'     => $sender_name,
            '{sender_email}'    => $sender_email,
            '{job_title}'       => $job_title,
            '{country}'         => $country_display,
            '{avatar_url}'      => esc_url($avatar_url),
            '{project_type}'    => esc_html($data['project_type'] ?? 'N/A'),
            '{project_length}'  => esc_html($data['project_length'] ?? 'N/A'),
            '{project_dates}'   => esc_html($data['project_dates'] ?? 'Flexible'),
            '{budget}'          => esc_html($data['budget'] ?? 'To be discussed'),
            '{message}'         => wp_kses_post(wpautop($data['message'] ?? '')),
            '{subject}'         => esc_html($data['subject'] ?? 'No Subject')
        ];

        $search  = array_keys($dictionary);
        $replace = array_values($dictionary);

        // Retrieve the repeater array of Email Templates
        $templates = get_option('dd_outreach_email_templates', $this->get_default_template_structure());

        $success = false;

        // Loop through each configured template and dispatch dynamically
        foreach ($templates as $tpl) {

            $to         = str_replace($search, $replace, $tpl['to']);
            $subject    = str_replace($search, $replace, $tpl['subject']);
            $from_name  = str_replace($search, $replace, $tpl['from_name']);
            $from_email = str_replace($search, $replace, $tpl['from_email']);
            $reply_to   = str_replace($search, $replace, $tpl['reply_to']);
            $cc         = str_replace($search, $replace, $tpl['cc']);
            $bcc        = str_replace($search, $replace, $tpl['bcc']);
            $body       = str_replace($search, $replace, $tpl['body']);

            // Compile the Mail Headers payload array
            $headers = ['Content-Type: text/html; charset=UTF-8'];

            if (!empty($from_email) && is_email($from_email)) {
                $headers[] = !empty($from_name) ? 'From: ' . $from_name . ' <' . $from_email . '>' : 'From: ' . $from_email;
            }
            if (!empty($reply_to)) {
                $headers[] = 'Reply-To: ' . $reply_to;
            }
            if (!empty($cc)) {
                $headers[] = 'Cc: ' . $cc;
            }
            if (!empty($bcc)) {
                $headers[] = 'Bcc: ' . $bcc;
            }

            // Ensure we have a valid parsed recipient before dispatching
            if (is_email($to)) {
                $sent = wp_mail($to, $subject, $body, $headers);
                if ($sent) $success = true;
            }
        }

        return $success;
    }

    /**
     * Injects frontend JavaScript for Elementor AJAX response.
     */
    public function inject_elementor_success_scripts()
    {
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof jQuery !== 'undefined') {
                    jQuery(document).on('submit_success', function(event, response) {
                        if (response && response.data && response.data.dd_custom_html) {
                            var $summaryTarget = jQuery('#outreach-form-summary');
                            var $pointsTarget = jQuery('.current-points');

                            if ($summaryTarget.length) {
                                $summaryTarget.html(response.data.dd_custom_html);
                                jQuery('#outreach-submission').addClass('hide-element');
                                jQuery('#outreach-summary').removeClass('hide-element');

                                if ($pointsTarget.length) {
                                    if (response.data.updated_points !== undefined) {
                                        $pointsTarget.text(response.data.updated_points);
                                        if (response.data.updated_points == 0 || response.data.updated_points == '0') {
                                            jQuery('.submit-new').remove();
                                            jQuery('body').addClass('reload--page');
                                        }
                                    } else {
                                        // Fallback calculation utilizing the dynamic cost returned via ajax
                                        var currentPointsStr = $pointsTarget.text().replace(/,/g, '');
                                        var currentVal = parseInt(currentPointsStr, 10);
                                        var cost = response.data.deducted_points ? parseInt(response.data.deducted_points, 10) : 1;
                                        if (!isNaN(currentVal) && currentVal >= cost) {
                                            $pointsTarget.text(currentVal - cost);
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            });
        </script>
    <?php
    }

    /**
     * Backend Meta Box setup for the wp-admin screen.
     */
    public function add_note_meta_box()
    {
        add_meta_box(
            'dd_outreach_note_meta',
            'Project Notes Monitor',
            [$this, 'render_note_meta_box'],
            'outreach',
            'side',
            'high'
        );
    }

    /**
     * Renders the HTML inside the backend meta box.
     */
    public function render_note_meta_box($post)
    {
        $notes = get_post_meta($post->ID, 'dd_outreach_project_notes', true);

        if (empty($notes) || !is_array($notes)) {
            echo '<p style="color:#666;">No notes have been added to this project yet.</p>';
            return;
        }

        echo '<div style="max-height: 500px; overflow-y: auto;">';
        foreach ($notes as $note) {
            echo '<div style="background: #fdf5e6; border: 1px solid #e0e0e0; padding: 12px; margin-bottom: 12px; border-radius: 4px;">';
            echo '<h4 style="margin: 0 0 5px 0;">' . esc_html($note['title']) . '</h4>';
            echo '<p style="margin: 0 0 10px 0; font-size: 13px; color: #444;">' . nl2br(esc_html($note['content'])) . '</p>';
            echo '<small style="color: #999;">Last edited: ' . esc_html(date_i18n('F jS, Y \a\t g:i a', strtotime($note['date']))) . '</small>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Renders the HTML block for all notes assigned to a post.
     */
    private function generate_notes_list_html($post_id)
    {
        $notes = get_post_meta($post_id, 'dd_outreach_project_notes', true);
        $html = '';

        if (empty($notes) || !is_array($notes)) {
            $html .= '<div class="dd-no-notes">No notes have been added to this project yet.</div>';
        } else {
            $notes = array_reverse($notes);

            foreach ($notes as $note) {
                $fmt_date = date_i18n('F jS, Y', strtotime($note['date']));
                $title    = esc_html($note['title']);
                $content  = nl2br(esc_html($note['content']));
                $raw      = esc_textarea($note['content']);
                $note_id  = esc_attr($note['id']);

                $html .= '<div class="dd-steps-card dd-note-card" data-note-id="' . $note_id . '">';
                $html .= '<h4 class="dd-note-title dd-display-note-title">' . $title . '</h4>';
                $html .= '<div class="dd-steps-content dd-display-note-content">' . $content . '</div>';
                $html .= '<textarea class="dd-raw-note-content" style="display:none;">' . $raw . '</textarea>';

                $html .= '<div class="dd-steps-actions">';
                $html .= '<button class="dd-delete-note dd-delete-btn dd-note-btn dd-note-btn-link" data-post-id="' . esc_attr($post_id) . '" data-note-id="' . $note_id . '"><span>DELETE NOTE</span></button>';
                $html .= '<button class="dd-edit-note dd-edit-btn dd-note-btn dd-note-btn-outline" data-post-id="' . esc_attr($post_id) . '" data-note-id="' . $note_id . '">  <svg xmlns="http://www.w3.org/2000/svg" width="12.832" height="16.332" viewBox="0 0 12.832 16.332">
                            <path id="saved" fill="currentColor" d="M26.125,10.333V22a.583.583,0,0,1-.583.583h-.083a.584.584,0,0,1-.416-.174l-4.167-4.243-4.167,4.243a.583.583,0,0,1-.416.174h-.083A.583.583,0,0,1,15.625,22V10.333a1.752,1.752,0,0,1,1.75-1.75h7a1.752,1.752,0,0,1,1.75,1.75ZM25.541,6.25h-7a.583.583,0,0,0,0,1.167h7a1.752,1.752,0,0,1,1.75,1.75V18.5a.583.583,0,1,0,1.167,0V9.166A2.92,2.92,0,0,0,25.541,6.25Z" transform="translate(-15.625 -6.25)" />
                        </svg> <span>EDIT NOTE</span></button>';
                $html .= '</div>';

                $html .= '<p class="dd-last-edited">Last edited ' . $fmt_date . '</p>';
                $html .= '</div>';
            }
        }
        return $html;
    }

    /**
     * AJAX endpoint to save or update a note from the frontend view dashboard.
     */
    public function ajax_save_outreach_note()
    {
        check_ajax_referer('dd_outreach_nonce', 'security');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Unauthorized.');
        }

        $note_id = isset($_POST['note_id']) ? sanitize_text_field($_POST['note_id']) : '';
        $title   = isset($_POST['note_title']) ? sanitize_text_field($_POST['note_title']) : '';
        $content = isset($_POST['note_content']) ? sanitize_textarea_field($_POST['note_content']) : '';
        $date    = current_time('mysql');

        $notes = get_post_meta($post_id, 'dd_outreach_project_notes', true);
        if (!is_array($notes)) $notes = [];

        if (!empty($note_id)) {
            foreach ($notes as &$note) {
                if ($note['id'] === $note_id) {
                    $note['title'] = $title;
                    $note['content'] = $content;
                    $note['date'] = $date;
                    break;
                }
            }
        } else {
            $notes[] = [
                'id'      => uniqid('note_'),
                'title'   => $title,
                'content' => $content,
                'date'    => $date
            ];
        }

        update_post_meta($post_id, 'dd_outreach_project_notes', $notes);
        wp_send_json_success($this->generate_notes_list_html($post_id));
    }

    /**
     * AJAX endpoint to delete a specific note from the frontend view dashboard.
     */
    public function ajax_delete_outreach_note()
    {
        check_ajax_referer('dd_outreach_nonce', 'security');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Unauthorized.');
        }

        $note_id = isset($_POST['note_id']) ? sanitize_text_field($_POST['note_id']) : '';
        $notes = get_post_meta($post_id, 'dd_outreach_project_notes', true);

        if (is_array($notes) && !empty($note_id)) {
            $notes = array_filter($notes, function ($note) use ($note_id) {
                return $note['id'] !== $note_id;
            });
            $notes = array_values($notes);
            update_post_meta($post_id, 'dd_outreach_project_notes', $notes);
        }

        wp_send_json_success($this->generate_notes_list_html($post_id));
    }

    /**
     * Renders the left-side panel (list and filters) via shortcode [dd_outreach_list].
     * Integrates dynamic dropdown options native to the plugin backend state.
     */
    public function render_list_shortcode($atts)
    {
        if (! is_user_logged_in()) {
            return '<p>Please log in to view your outreach.</p>';
        }

        $raw_fields = get_query_var('influencer_outreach_fields');
        $influencer_outreach_fields = is_array($raw_fields) ? $raw_fields : [];

        // Fetch dynamic filter options explicitly
        $types_raw = get_option('dd_outreach_project_types', '');
        $types_arr = array_filter(array_map('trim', explode("\n", $types_raw)));
        $current_type = $influencer_outreach_fields['project_type'] ?? '';

        $lengths_raw = get_option('dd_outreach_project_lengths', '');
        $lengths_arr = array_filter(array_map('trim', explode("\n", $lengths_raw)));
        $current_length = $influencer_outreach_fields['project_length'] ?? '';

        ob_start();
    ?>
        <div class="dd-dashboard-list-container">
            <div class="outreach-filter">
                <div class="influencer-search-filter-holder">
                    <div class="influencer-search-item">
                        <input type="text" id="dd-outreach-search" name="search" placeholder="Search by influencer or message">
                    </div>

                    <div class="influencer-search-item">
                        <select name="project_type" class="dd-filter-select">
                            <option value="">Filter by project type</option>
                            <?php foreach ($types_arr as $type) : ?>
                                <?php
                                $val = strpos($type, '|') !== false ? explode('|', $type)[0] : $type;
                                $label = strpos($type, '|') !== false ? explode('|', $type)[1] : $type;
                                ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php selected($current_type, $val); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="influencer-search-item">
                        <select name="project_length" class="dd-filter-select">
                            <option value="">Filter by project length</option>
                            <?php foreach ($lengths_arr as $length) : ?>
                                <?php
                                $val = strpos($length, '|') !== false ? explode('|', $length)[0] : $length;
                                $label = strpos($length, '|') !== false ? explode('|', $length)[1] : $length;
                                ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php selected($current_length, $val); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="dd-item-list" id="dd-outreach-list-container">
                <?php echo $this->generate_list_html(); ?>
            </div>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Renders the dynamic credit cost via shortcode [dd_outreach_credit_cost].
     */
    public function render_credit_cost_shortcode($atts)
    {
        $cost = get_option('dd_outreach_credit_cost', 1);
        return esc_html($cost);
    }

    /**
     * Helper function to generate the HTML for the item list.
     */
    private function generate_list_html($search_query = '', $project_types = [], $project_lengths = [])
    {
        $args = [
            'post_type'      => 'outreach',
            'posts_per_page' => -1,
            'author'         => get_current_user_id(),
            'post_status'    => 'publish'
        ];

        if (!empty($search_query)) {
            $args['s'] = sanitize_text_field($search_query);
        }

        $meta_query = ['relation' => 'AND'];

        if (!empty($project_types) && is_array($project_types)) {
            $type_query = ['relation' => 'OR'];
            foreach ($project_types as $type) {
                if (!empty($type)) {
                    $type_query[] = [
                        'key'     => 'project_type',
                        'value'   => sanitize_text_field($type),
                        'compare' => 'LIKE'
                    ];
                }
            }
            if (count($type_query) > 1) {
                $meta_query[] = $type_query;
            }
        }

        if (!empty($project_lengths) && is_array($project_lengths)) {
            $length_query = ['relation' => 'OR'];
            foreach ($project_lengths as $length) {
                if (!empty($length)) {
                    $length_query[] = [
                        'key'     => 'project_length',
                        'value'   => sanitize_text_field($length),
                        'compare' => 'LIKE'
                    ];
                }
            }
            if (count($length_query) > 1) {
                $meta_query[] = $length_query;
            }
        }

        if (count($meta_query) > 1) {
            $args['meta_query'] = $meta_query;
        }

        $query = new WP_Query($args);
        $html = '';

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $influencer_id = get_post_meta($post_id, 'influencer_id', true);
                $influencer_handle = do_shortcode('[instagram_id id="' . $influencer_id . '"]');
                $influencer_name = $influencer_id ? get_the_title($influencer_id) : 'Unknown Creator';


                $title = get_the_title();
                $date = get_the_date('M j, Y');

                $html .= '<div class="dd-outreach-item" data-post-id="' . esc_attr($post_id) . '">';
                $html .= '<div class="avatar-holder">';
                $html .= do_shortcode('[influencer_avatar post_id="' . esc_attr($influencer_id) . '"]');
                $html .= '</div>';
                $html .= '<div class="dd-item-content">';
                $html .= '<span class="dd-item-name">' . esc_html($influencer_name) . '</span>';
                $html .= '<span class="dd-item-handle">@' . esc_html($influencer_handle) . '</span>';
                $html .= '<span class="dd-item-title">' . esc_html($title) . '</span>';
                $html .= '<span class="dd-item-date">' . esc_html($date) . '</span>';
                $html .= '</div></div>';
            }
            wp_reset_postdata();
        } else {
            $html .= '<p style="padding: 20px; color:#888;">No outreach found matching your criteria.</p>';
        }

        return $html;
    }

    /**
     * AJAX endpoint to filter the outreach list.
     */
    public function ajax_filter_outreach_list()
    {
        check_ajax_referer('dd_outreach_nonce', 'security');

        if (! is_user_logged_in()) {
            wp_send_json_error('Please log in.');
        }

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

        $project_types = isset($_POST['project_type']) && is_array($_POST['project_type']) ? array_map('sanitize_text_field', $_POST['project_type']) : [];
        $project_lengths = isset($_POST['project_length']) && is_array($_POST['project_length']) ? array_map('sanitize_text_field', $_POST['project_length']) : [];

        $html = $this->generate_list_html($search, $project_types, $project_lengths);

        wp_send_json_success($html);
    }

    /**
     * Renders the right-side panel placeholder via shortcode [dd_outreach_view].
     */
    public function render_view_shortcode($atts)
    {
        return '<div id="dd-outreach-view-container" class="dd-outreach-view-container">
            <span class="dd-view-placeholder">Loading...</span>
        </div>';
    }

    /**
     * AJAX endpoint to fetch specific outreach post details.
     * Re-factored payload to inject `.dd-modal-content-wrapper` and the mobile close trigger for responsive viewing.
     */
    public function ajax_get_outreach_details()
    {
        check_ajax_referer('dd_outreach_nonce', 'security');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (! $post_id || get_post_type($post_id) !== 'outreach') {
            wp_send_json_error('<span class="dd-view-error">Invalid post ID.</span>');
        }

        $post = get_post($post_id);

        if ($post->post_author != get_current_user_id() && ! current_user_can('manage_options')) {
            wp_send_json_error('<span class="dd-view-error">Unauthorized.</span>');
        }

        $influencer_id = get_post_meta($post_id, 'influencer_id', true);
        $influencer_name = $influencer_id ? get_the_title($influencer_id) : 'Unknown Creator';
        $influencer_handle = do_shortcode('[instagram_id id="' . $influencer_id . '"]');

        $message        = get_post_meta($post_id, 'message', true) ?: 'No message provided.';
        $sent_date      = get_the_date('g:i A, F jS Y', $post_id);

        ob_start();
    ?>
        <div class="dd-modal-content-wrapper">
            <button class="dd-close-modal" id="dd-close-modal">&times;</button>

            <div class="dd-message-overview-container mt-0">
                <div class="dd-profile-header">
                    <div class="avatar-holder" style="--size: 84px">
                        <?= do_shortcode('[influencer_avatar post_id="' . esc_attr($influencer_id) . '"]') ?>
                    </div>
                    <div class="dd-profile-info">
                        <strong><?php echo esc_html($influencer_name); ?> </strong><br>
                        <small>@<?php echo esc_html($influencer_handle); ?></small>
                    </div>
                    <a href="<?php echo get_permalink($influencer_id); ?>" class="dd-btn-outline">VIEW CREATOR PROFILE</a>
                </div>
                <div class="dd-overview-body">
                    <div class="dd-message-sent-date">
                        <span>Sent at <?php echo esc_html($sent_date); ?></span>
                    </div>

                    <h3 class="dd-subject-title"><?php echo esc_html($post->post_title); ?></h3>

                    <div class="dd-message-content">
                        <?php echo wp_kses_post($message); ?>
                    </div>
                </div>
            </div>

            <div class="dd-notes-grid">
                <div class="dd-note-card">
                    <h4 class="dd-note-title" id="dd-note-form-heading">🗒️ Create a note for this project</h4>
                    <p class="dd-note-desc">Notes created are only visible to you and will never be shared.</p>
                    <input type="hidden" id="dd-note-input-id" value="">
                    <input type="text" id="dd-note-input-title" class="dd-note-input" placeholder="Note title">
                    <textarea id="dd-note-input-content" class="dd-note-textarea" placeholder="Start typing your note..."></textarea>
                    <div style="display:flex; gap:10px;">
                        <button id="dd-save-note" class="dd-note-btn dd-note-btn-yellow" data-post-id="<?php echo esc_attr($post_id); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12.832" height="16.332" viewBox="0 0 12.832 16.332">
                                <path id="saved" fill="currentColor" d="M26.125,10.333V22a.583.583,0,0,1-.583.583h-.083a.584.584,0,0,1-.416-.174l-4.167-4.243-4.167,4.243a.583.583,0,0,1-.416.174h-.083A.583.583,0,0,1,15.625,22V10.333a1.752,1.752,0,0,1,1.75-1.75h7a1.752,1.752,0,0,1,1.75,1.75ZM25.541,6.25h-7a.583.583,0,0,0,0,1.167h7a1.752,1.752,0,0,1,1.75,1.75V18.5a.583.583,0,1,0,1.167,0V9.166A2.92,2.92,0,0,0,25.541,6.25Z" transform="translate(-15.625 -6.25)" />
                            </svg>
                            SAVE NOTE
                        </button>
                        <button id="dd-cancel-edit-note" class="dd-delete-btn dd-note-btn dd-note-btn-link" style="display:none; margin-top:10px;">CANCEL</button>
                    </div>
                </div>

                <div class="dd-notes-list-container" id="dd-notes-list-wrapper">
                    <?php echo $this->generate_notes_list_html($post_id); ?>
                </div>
            </div>
        </div>
<?php
        $html = ob_get_clean();
        wp_send_json_success($html);
    }

    /**
     * Converts a 2-letter ISO country code to its corresponding Emoji flag and full name.
     */
    private function get_country_display($country_code)
    {
        if (empty($country_code)) {
            return '';
        }

        $country_code = strtoupper(trim($country_code));

        $flag = '';
        if (preg_match('/^[A-Z]{2}$/', $country_code)) {
            $char1 = ord($country_code[0]) + 127397;
            $char2 = ord($country_code[1]) + 127397;
            $flag = "&#{$char1};&#{$char2};";
        }

        $country_names = [
            'AF' => 'Afghanistan',
            'AX' => 'Åland Islands',
            'AL' => 'Albania',
            'DZ' => 'Algeria',
            'AS' => 'American Samoa',
            'AD' => 'Andorra',
            'AO' => 'Angola',
            'AI' => 'Anguilla',
            'AQ' => 'Antarctica',
            'AG' => 'Antigua and Barbuda',
            'AR' => 'Argentina',
            'AM' => 'Armenia',
            'AW' => 'Aruba',
            'AU' => 'Australia',
            'AT' => 'Austria',
            'AZ' => 'Azerbaijan',
            'BS' => 'Bahamas',
            'BH' => 'Bahrain',
            'BD' => 'Bangladesh',
            'BB' => 'Barbados',
            'BY' => 'Belarus',
            'BE' => 'Belgium',
            'BZ' => 'Belize',
            'BJ' => 'Benin',
            'BM' => 'Bermuda',
            'BT' => 'Bhutan',
            'BO' => 'Bolivia (Plurinational State of)',
            'BQ' => 'Bonaire, Sint Eustatius and Saba',
            'BA' => 'Bosnia and Herzegovina',
            'BW' => 'Botswana',
            'BV' => 'Bouvet Island',
            'BR' => 'Brazil',
            'IO' => 'British Indian Ocean Territory',
            'BN' => 'Brunei Darussalam',
            'BG' => 'Bulgaria',
            'BF' => 'Burkina Faso',
            'BI' => 'Burundi',
            'CV' => 'Cabo Verde',
            'KH' => 'Cambodia',
            'CM' => 'Cameroon',
            'CA' => 'Canada',
            'KY' => 'Cayman Islands',
            'CF' => 'Central African Republic',
            'TD' => 'Chad',
            'CL' => 'Chile',
            'CN' => 'China',
            'CX' => 'Christmas Island',
            'CC' => 'Cocos (Keeling) Islands',
            'CO' => 'Colombia',
            'KM' => 'Comoros',
            'CG' => 'Congo',
            'CD' => 'Congo, Democratic Republic of the',
            'CK' => 'Cook Islands',
            'CR' => 'Costa Rica',
            'CI' => 'Côte d\'Ivoire',
            'HR' => 'Croatia',
            'CU' => 'Cuba',
            'CW' => 'Curaçao',
            'CY' => 'Cyprus',
            'CZ' => 'Czechia',
            'DK' => 'Denmark',
            'DJ' => 'Djibouti',
            'DM' => 'Dominica',
            'DO' => 'Dominican Republic',
            'EC' => 'Ecuador',
            'EG' => 'Egypt',
            'SV' => 'El Salvador',
            'GQ' => 'Equatorial Guinea',
            'ER' => 'Eritrea',
            'EE' => 'Estonia',
            'SZ' => 'Eswatini',
            'ET' => 'Ethiopia',
            'FK' => 'Falkland Islands (Malvinas)',
            'FO' => 'Faroe Islands',
            'FJ' => 'Fiji',
            'FI' => 'Finland',
            'FR' => 'France',
            'GF' => 'French Guiana',
            'PF' => 'French Polynesia',
            'TF' => 'French Southern Territories',
            'GA' => 'Gabon',
            'GM' => 'Gambia',
            'GE' => 'Georgia',
            'DE' => 'Germany',
            'GH' => 'Ghana',
            'GI' => 'Gibraltar',
            'GR' => 'Greece',
            'GL' => 'Greenland',
            'GD' => 'Grenada',
            'GP' => 'Guadeloupe',
            'GU' => 'Guam',
            'GT' => 'Guatemala',
            'GG' => 'Guernsey',
            'GN' => 'Guinea',
            'GW' => 'Guinea-Bissau',
            'GY' => 'Guyana',
            'HT' => 'Haiti',
            'HM' => 'Heard Island and McDonald Islands',
            'VA' => 'Holy See',
            'HN' => 'Honduras',
            'HK' => 'Hong Kong',
            'HU' => 'Hungary',
            'IS' => 'Iceland',
            'IN' => 'India',
            'ID' => 'Indonesia',
            'IR' => 'Iran (Islamic Republic of)',
            'IQ' => 'Iraq',
            'IE' => 'Ireland',
            'IM' => 'Isle of Man',
            'IL' => 'Israel',
            'IT' => 'Italy',
            'JM' => 'Jamaica',
            'JP' => 'Japan',
            'JE' => 'Jersey',
            'JO' => 'Jordan',
            'KZ' => 'Kazakhstan',
            'KE' => 'Kenya',
            'KI' => 'Kiribati',
            'KP' => 'Korea (Democratic People\'s Republic of)',
            'KR' => 'Korea, Republic of',
            'KW' => 'Kuwait',
            'KG' => 'Kyrgyzstan',
            'LA' => 'Lao People\'s Democratic Republic',
            'LV' => 'Latvia',
            'LB' => 'Lebanon',
            'LS' => 'Lesotho',
            'LR' => 'Liberia',
            'LY' => 'Libya',
            'LI' => 'Liechtenstein',
            'LT' => 'Lithuania',
            'LU' => 'Luxembourg',
            'MO' => 'Macao',
            'MG' => 'Madagascar',
            'MW' => 'Malawi',
            'MY' => 'Malaysia',
            'MV' => 'Maldives',
            'ML' => 'Mali',
            'MT' => 'Malta',
            'MH' => 'Marshall Islands',
            'MQ' => 'Martinique',
            'MR' => 'Mauritania',
            'MU' => 'Mauritius',
            'YT' => 'Mayotte',
            'MX' => 'Mexico',
            'FM' => 'Micronesia (Federated States of)',
            'MD' => 'Moldova, Republic of',
            'MC' => 'Monaco',
            'MN' => 'Mongolia',
            'ME' => 'Montenegro',
            'MS' => 'Montserrat',
            'MA' => 'Morocco',
            'MZ' => 'Mozambique',
            'MM' => 'Myanmar',
            'NA' => 'Namibia',
            'NR' => 'Nauru',
            'NP' => 'Nepal',
            'NL' => 'Netherlands',
            'NC' => 'New Caledonia',
            'NZ' => 'New Zealand',
            'NI' => 'Nicaragua',
            'NE' => 'Niger',
            'NG' => 'Nigeria',
            'NU' => 'Niue',
            'NF' => 'Norfolk Island',
            'MK' => 'North Macedonia',
            'MP' => 'Northern Mariana Islands',
            'NO' => 'Norway',
            'OM' => 'Oman',
            'PK' => 'Pakistan',
            'PW' => 'Palau',
            'PS' => 'Palestine, State of',
            'PA' => 'Panama',
            'PG' => 'Papua New Guinea',
            'PY' => 'Paraguay',
            'PE' => 'Peru',
            'PH' => 'Philippines',
            'PN' => 'Pitcairn',
            'PL' => 'Poland',
            'PT' => 'Portugal',
            'PR' => 'Puerto Rico',
            'QA' => 'Qatar',
            'RE' => 'Réunion',
            'RO' => 'Romania',
            'RU' => 'Russian Federation',
            'RW' => 'Rwanda',
            'BL' => 'Saint Barthélemy',
            'SH' => 'Saint Helena, Ascension and Tristan da Cunha',
            'KN' => 'Saint Kitts and Nevis',
            'LC' => 'Saint Lucia',
            'MF' => 'Saint Martin (French part)',
            'PM' => 'Saint Pierre and Miquelon',
            'VC' => 'Saint Vincent and the Grenadines',
            'WS' => 'Samoa',
            'SM' => 'San Marino',
            'ST' => 'Sao Tome and Principe',
            'SA' => 'Saudi Arabia',
            'SN' => 'Senegal',
            'RS' => 'Serbia',
            'SC' => 'Seychelles',
            'SL' => 'Sierra Leone',
            'SG' => 'Singapore',
            'SX' => 'Sint Maarten (Dutch part)',
            'SK' => 'Slovakia',
            'SI' => 'Slovenia',
            'SB' => 'Solomon Islands',
            'SO' => 'Somalia',
            'ZA' => 'South Africa',
            'GS' => 'South Georgia and the South Sandwich Islands',
            'SS' => 'South Sudan',
            'ES' => 'Spain',
            'LK' => 'Sri Lanka',
            'SD' => 'Sudan',
            'SR' => 'Suriname',
            'SJ' => 'Svalbard and Jan Mayen',
            'SE' => 'Sweden',
            'CH' => 'Switzerland',
            'SY' => 'Syrian Arab Republic',
            'TW' => 'Taiwan, Province of China',
            'TJ' => 'Tajikistan',
            'TZ' => 'Tanzania, United Republic of',
            'TH' => 'Thailand',
            'TL' => 'Timor-Leste',
            'TG' => 'Togo',
            'TK' => 'Tokelau',
            'TO' => 'Tonga',
            'TT' => 'Trinidad and Tobago',
            'TN' => 'Tunisia',
            'TR' => 'Turkey',
            'TM' => 'Turkmenistan',
            'TC' => 'Turks and Caicos Islands',
            'TV' => 'Tuvalu',
            'UG' => 'Uganda',
            'UA' => 'Ukraine',
            'AE' => 'United Arab Emirates',
            'GB' => 'United Kingdom',
            'US' => 'United States of America',
            'UM' => 'United States Minor Outlying Islands',
            'UY' => 'Uruguay',
            'UZ' => 'Uzbekistan',
            'VU' => 'Vanuatu',
            'VE' => 'Venezuela (Bolivarian Republic of)',
            'VN' => 'Viet Nam',
            'VG' => 'Virgin Islands (British)',
            'VI' => 'Virgin Islands (U.S.)',
            'WF' => 'Wallis and Futuna',
            'EH' => 'Western Sahara',
            'YE' => 'Yemen',
            'ZM' => 'Zambia',
            'ZW' => 'Zimbabwe'
        ];

        $name = isset($country_names[$country_code]) ? $country_names[$country_code] : esc_html($country_code);

        return $flag . ' ' . $name;
    }
}

new DD_Outreach_Manager();
