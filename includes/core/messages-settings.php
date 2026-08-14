<?php
if (! defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Editable user-facing messages/notices — a "Messages" tab on the Influencer
// Theme settings hub (see includes/core/admin-settings.php for the tab
// mechanism). One central registry drives the settings fields, the PHP
// accessor (dd_get_message), and the JS payload (dd_js_messages) so every
// call site stays in sync.
// ---------------------------------------------------------------------------

/**
 * Single source of truth for every editable message: its option key, label,
 * default text, settings-tab group, and whether it must also reach front-end
 * JS. `multiline` renders a textarea instead of a text input; `html` allows a
 * small set of inline tags (used by the two messages that carry <strong>).
 *
 * @return array<string, array{label:string, default:string, group:string, desc:string, js?:bool, multiline?:bool, html?:bool}>
 */
function dd_message_definitions()
{
    return [
        // --- Plan & Upgrade Prompts -----------------------------------------------------
        'dd_msg_search_limit' => [
            'label'   => 'Search Limit Reached',
            'default' => "You've reached your plan's creator search limit.",
            'group'   => 'plan_gates',
            'desc'    => 'Shown when a user hits their plan\'s creator search cap (search page redirect, AJAX response, the client-side popup, and the Account Notice widget).',
            'js'      => true,
        ],
        'dd_msg_company_trial_block' => [
            'label'   => 'Company Trial Limit',
            'default' => 'Your company already has a trial account. Upgrade to a paid plan to search for creators.',
            'group'   => 'plan_gates',
            'desc'    => 'Shown instead of the normal search-limit notice when a second trial account from the same company email domain tries to search (search flow and the Account Notice widget).',
            'js'      => true,
        ],
        'dd_msg_notice_upgrade_cta' => [
            'label'   => 'Account Notice Upgrade Button',
            'default' => 'Upgrade your plan',
            'group'   => 'plan_gates',
            'desc'    => 'Button label on the Account Notice widget shown when a user has no searches left. Also used as the popup button on a plan-gated page.',
            'js'      => true,
        ],
        'dd_msg_gate_members_only' => [
            'label'   => 'Restricted Page Popup (Members Only)',
            'default' => 'This page is for members only. Please log in to continue.',
            'group'   => 'plan_gates',
            'desc'    => 'Popup shown when a logged-out visitor tries to open a members-only page, a Dashboard-template page, or a locked profile link.',
            'js'      => true,
        ],
        'dd_msg_gate_influencer' => [
            'label'   => 'Restricted Page Popup (Creator Profile)',
            'default' => "Log in to view this creator's full profile.",
            'group'   => 'plan_gates',
            'desc'    => 'Popup shown when a logged-out visitor tries to open a creator profile page.',
            'js'      => true,
        ],
        'dd_msg_gate_plan' => [
            'label'   => 'Restricted Page Popup (Plan Gate)',
            'default' => "This page isn't included in your current plan.",
            'group'   => 'plan_gates',
            'desc'    => "Popup shown when a logged-in visitor's plan doesn't include a PMPro-gated page.",
            'js'      => true,
        ],
        'dd_msg_gate_login_cta' => [
            'label'   => 'Restricted Page Popup Login Button',
            'default' => 'Log in',
            'group'   => 'plan_gates',
            'desc'    => 'Button label on the restricted-page popup for logged-out visitors.',
            'js'      => true,
        ],
        'dd_msg_gate_close' => [
            'label'   => 'Restricted Page Popup Close Button',
            'default' => 'Close',
            'group'   => 'plan_gates',
            'desc'    => 'Dismiss-button label on the restricted-page popup.',
            'js'      => true,
        ],
        'dd_msg_export_pdf_gate' => [
            'label'   => 'Export PDF Gate',
            'default' => 'Exporting saved lists to PDF is not available on your current plan. Upgrade your plan to unlock this feature.',
            'group'   => 'plan_gates',
            'desc'    => 'Shown when a user without PDF export access tries to export a saved list.',
            'js'      => true,
        ],
        'dd_msg_save_search_gate' => [
            'label'   => 'Save Search Gate (popup)',
            'default' => 'Saving searches is not available on your current plan. Upgrade your plan to unlock this feature.',
            'group'   => 'plan_gates',
            'desc'    => 'Client-side confirm shown when clicking a locked "Save this search" trigger.',
            'js'      => true,
        ],
        'dd_msg_saved_lists_gate' => [
            'label'   => 'Saved Lists Gate',
            'default' => 'Your plan does not include saved lists. Please upgrade to continue.',
            'group'   => 'plan_gates',
            'desc'    => 'Server-side rejection when saving/updating an influencer to a list without access.',
        ],
        'dd_msg_saved_search_gate' => [
            'label'   => 'Saved Search Gate',
            'default' => 'Your plan does not include saved searches. Please upgrade to continue.',
            'group'   => 'plan_gates',
            'desc'    => 'Server-side rejection when saving a search without access.',
        ],
        'dd_msg_outreach_gate' => [
            'label'   => 'Outreach/Contact Gate',
            'default' => 'Your plan does not include contacting creators. Please upgrade to continue.',
            'group'   => 'plan_gates',
            'desc'    => 'Server-side rejection when submitting the outreach form without access.',
        ],
        'dd_msg_contact_upgrade_tooltip' => [
            'label'   => 'Contact Button Upgrade Tooltip',
            'default' => 'Upgrade your plan to contact creators',
            'group'   => 'plan_gates',
            'desc'    => 'Tooltip on the "Upgrade to contact" button for unlocked creators when outreach isn\'t included in the plan.',
        ],
        'dd_msg_contact_upgrade_btn' => [
            'label'   => 'Contact Button Upgrade Label',
            'default' => 'Upgrade to contact',
            'group'   => 'plan_gates',
            'desc'    => 'Button text shown in place of the contact button when outreach isn\'t included in the plan.',
        ],
        'dd_msg_save_upgrade_tooltip' => [
            'label'   => 'Save Button Upgrade Tooltip',
            'default' => 'Upgrade your plan to save creators',
            'group'   => 'plan_gates',
            'desc'    => 'Tooltip on the disabled save-to-list button when saved lists aren\'t included in the plan.',
        ],
        'dd_msg_save_search_upgrade_tooltip' => [
            'label'   => 'Save Search Upgrade Tooltip',
            'default' => 'Upgrade your plan to save searches',
            'group'   => 'plan_gates',
            'desc'    => 'Tooltip on the locked "Save this search" trigger when saved search isn\'t included in the plan.',
        ],
        'dd_msg_unlock_locked_hint' => [
            'label'   => 'Unlock First Hint',
            'default' => "Unlock this creator's full profile first",
            'group'   => 'plan_gates',
            'desc'    => 'Tooltip on the disabled save/contact buttons for a creator the user hasn\'t unlocked yet.',
        ],
        'dd_msg_lock_cta' => [
            'label'   => 'Locked Feature Upgrade Button',
            'default' => 'Upgrade your plan',
            'group'   => 'plan_gates',
            'desc'    => 'Button label shown on the padlock overlay covering a premium feature a user\'s plan doesn\'t include.',
            'js'      => true,
        ],
        'dd_msg_lock_blurb_export_pdf' => [
            'label'   => 'Locked Feature Blurb — Export PDF',
            'default' => 'Export your saved lists as a polished, shareable PDF.',
            'group'   => 'plan_gates',
            'desc'    => 'One-line benefit shown on the padlock overlay for the Export PDF feature.',
        ],
        'dd_msg_lock_blurb_outreach' => [
            'label'   => 'Locked Feature Blurb — Outreach',
            'default' => 'Contact creators directly from their profile.',
            'group'   => 'plan_gates',
            'desc'    => 'One-line benefit shown on the padlock overlay for the Outreach/Contact feature.',
        ],
        'dd_msg_lock_blurb_saved_lists' => [
            'label'   => 'Locked Feature Blurb — Saved Lists',
            'default' => 'Organise creators into campaign-ready lists.',
            'group'   => 'plan_gates',
            'desc'    => 'One-line benefit shown on the padlock overlay for the Saved Lists feature.',
        ],
        'dd_msg_lock_blurb_custom_outreach_message' => [
            'label'   => 'Locked Feature Blurb — Custom Outreach Message',
            'default' => 'Write your own outreach message instead of the standard template.',
            'group'   => 'plan_gates',
            'desc'    => 'One-line benefit shown on the padlock overlay for the Custom Outreach Message feature.',
        ],
        'dd_msg_lock_blurb_saved_search' => [
            'label'   => 'Locked Feature Blurb — Saved Search',
            'default' => 'Save this search and get back to it any time.',
            'group'   => 'plan_gates',
            'desc'    => 'One-line benefit shown on the padlock overlay for the Saved Search feature.',
        ],

        // --- Dashboard Activity --------------------------------------------------------------
        'dd_msg_no_recently_viewed' => [
            'label'   => 'No Recently Viewed Creators',
            'default' => "You haven't viewed any creators yet.",
            'group'   => 'dashboard',
            'desc'    => 'Shown in place of the Recently Viewed Influencers list on the dashboard when the current user has no viewed creators.',
        ],

        // --- Onboarding ---------------------------------------------------------------------
        'dd_msg_ob_welcome_title' => [
            'label'   => 'Welcome Popup Title',
            'default' => "You're in! Let's find your first creators.",
            'group'   => 'onboarding',
            'desc'    => 'Heading of the welcome popup shown the first time a new member reaches the dashboard.',
            'js'      => true,
        ],
        'dd_msg_ob_welcome_body' => [
            'label'     => 'Welcome Popup Body',
            'default'   => "Describe your brand and what you're looking for, and we'll match you with creators in seconds. Ready to run your first search?",
            'group'     => 'onboarding',
            'desc'      => 'Body copy of the welcome popup.',
            'js'        => true,
            'multiline' => true,
        ],
        'dd_msg_ob_welcome_cta' => [
            'label'   => 'Welcome Popup Primary Button',
            'default' => 'Start your first search',
            'group'   => 'onboarding',
            'desc'    => 'Primary button label on the welcome popup — links to the search page.',
            'js'      => true,
        ],
        'dd_msg_ob_tour_cta' => [
            'label'   => 'Welcome Popup Tour Button',
            'default' => 'Show me around first',
            'group'   => 'onboarding',
            'desc'    => 'Secondary button on the welcome popup that starts the step-by-step guided tour instead.',
            'js'      => true,
        ],
        'dd_msg_ob_skip' => [
            'label'   => 'Tour Skip Button',
            'default' => 'Skip',
            'group'   => 'onboarding',
            'desc'    => 'Skip-button label shown on every guided tour step.',
            'js'      => true,
        ],
        'dd_msg_ob_next' => [
            'label'   => 'Tour Next Button',
            'default' => 'Next',
            'group'   => 'onboarding',
            'desc'    => 'Next-button label shown on every guided tour step.',
            'js'      => true,
        ],
        'dd_msg_ob_back' => [
            'label'   => 'Tour Back Button',
            'default' => 'Back',
            'group'   => 'onboarding',
            'desc'    => 'Back-button label shown on every guided tour step after the first.',
            'js'      => true,
        ],
        'dd_msg_ob_step_counter' => [
            'label'   => 'Tour Step Counter',
            'default' => 'Step %s of %s',
            'group'   => 'onboarding',
            'desc'    => 'Step counter shown on each guided tour tooltip. Keep both %s tokens — they are replaced with the current step and total step count.',
            'js'      => true,
        ],
        'dd_msg_ob_done' => [
            'label'   => 'Tour Final Step Button',
            'default' => 'Done',
            'group'   => 'onboarding',
            'desc'    => 'Button label shown on the last step of the guided tour instead of "Next".',
            'js'      => true,
        ],
        'dd_msg_ob_tour_unavailable' => [
            'label'   => 'Tour Unavailable Notice',
            'default' => "The guided tour isn't available on this page yet.",
            'group'   => 'onboarding',
            'desc'    => 'Shown when a "Take a tour" trigger is clicked but no tour steps apply to the current page (e.g. none are configured, or none of their targets are found here).',
            'js'      => true,
        ],
        'dd_msg_ob_tour_button' => [
            'label'   => 'Tour Button Label',
            'default' => 'Take a quick tour',
            'group'   => 'onboarding',
            'desc'    => 'Default label for the "Start Guided Tour" Elementor widget/[onboarding_tour_button] shortcode — overridden per-instance by the widget\'s own Text field when set.',
        ],

        // --- Unlock & Credit Confirmations -----------------------------------------------
        'dd_msg_unlock_spend_confirm' => [
            'label'   => 'Unlock Spend Confirmation',
            'default' => "You're about to spend 1 credit to unlock this creator's contact information. Credits are non-refundable once spent — would you like to continue?",
            'group'   => 'unlock',
            'desc'    => 'Confirm dialog shown before spending a credit via the myCred "buy content" button.',
            'js'      => true,
        ],
        'dd_msg_unlock_modal_body' => [
            'label'     => 'Unlock Confirm Modal Body',
            'default'   => 'Unlocking this creator will deduct <strong>1 credit</strong> from your balance and automatically add them to your <strong>"Unlocked Influencers"</strong> saved list.',
            'group'     => 'unlock',
            'desc'      => 'Body text of the "Unlock Creator" confirmation modal. Supports <strong> tags.',
            'multiline' => true,
            'html'      => true,
        ],
        'dd_msg_creator_unlocked_heading' => [
            'label'   => 'Creator Unlocked Notice Heading',
            'default' => 'Creator Unlocked',
            'group'   => 'unlock',
            'desc'    => 'Heading of the toast notice shown right after a creator is unlocked.',
        ],
        'dd_msg_creator_unlocked_body' => [
            'label'     => 'Creator Unlocked Notice Body',
            'default'   => '%1$s deducted. New balance: <strong>%2$s</strong>. %3$s',
            'group'     => 'unlock',
            'desc'      => 'Body of the toast notice shown right after a creator is unlocked. Keep the tokens — %1$s is the amount just deducted (e.g. "1 credit"), %2$s is the new balance, %3$s is a sentence naming what that balance still buys (blank when unavailable).',
            'multiline' => true,
            'html'      => true,
        ],
        'dd_msg_unlock_no_credits_hint' => [
            'label'   => 'Out of Credits Hint',
            'default' => "You're out of credits — top up or upgrade to unlock more creators",
            'group'   => 'unlock',
            'desc'    => 'Tooltip on the locked save/contact buttons when the user has run out of credits (rather than simply not having unlocked this creator yet).',
        ],
        'dd_msg_unlock_no_credits_heading' => [
            'label'   => 'Out of Credits Modal Heading',
            'default' => "You're out of credits",
            'group'   => 'unlock',
            'desc'    => 'Heading of the popup shown when a user with 0 credits tries to unlock a creator.',
        ],
        'dd_msg_unlock_no_credits_body' => [
            'label'     => 'Out of Credits Modal Body',
            'default'   => "You've used all the creator unlocks and messages included in your plan. Top up your credits to keep going, or upgrade your plan for a bigger monthly allowance.",
            'group'     => 'unlock',
            'desc'      => 'Body text of the out-of-credits popup. Supports <strong> tags.',
            'multiline' => true,
            'html'      => true,
        ],
        'dd_msg_unlock_buy_credits_btn' => [
            'label'   => 'Out of Credits — Buy Credits Button',
            'default' => 'Buy more credits',
            'group'   => 'unlock',
            'desc'    => 'Label of the "buy credits" button on the out-of-credits popup.',
        ],
        'dd_msg_unlock_upgrade_btn' => [
            'label'   => 'Out of Credits — Upgrade Button',
            'default' => 'Upgrade your plan',
            'group'   => 'unlock',
            'desc'    => 'Label of the "upgrade plan" button on the out-of-credits popup.',
        ],
        'dd_msg_credits_detail_unlock' => [
            'label'   => 'Credits Ticker — Unlocks Noun',
            'default' => '%s creator unlocks',
            'group'   => 'unlock',
            'desc'    => 'One clause of the credits ticker\'s "24 creator unlocks or 24 messages" conversion line. Keep the %s token — it is replaced with how many unlocks the current balance covers.',
            'js'      => true,
        ],
        'dd_msg_credits_detail_message' => [
            'label'   => 'Credits Ticker — Messages Noun',
            'default' => '%s messages',
            'group'   => 'unlock',
            'desc'    => 'The other clause of the credits ticker\'s conversion line, omitted entirely for a plan without outreach access. Keep the %s token — it is replaced with how many messages the current balance covers.',
            'js'      => true,
        ],
        'dd_msg_credits_detail_join' => [
            'label'   => 'Credits Ticker — Join Word',
            'default' => 'or',
            'group'   => 'unlock',
            'desc'    => 'Word joining the credits ticker\'s clauses, e.g. "24 creator unlocks OR 24 messages".',
            'js'      => true,
        ],

        // --- Confirmation Dialogs ---------------------------------------------------------
        'dd_msg_confirm_delete_group' => [
            'label'   => 'Delete Group Confirmation',
            'default' => 'Are you sure you want to delete this group? This will remove the group from all saved creators.',
            'group'   => 'confirm',
            'desc'    => 'Shown before deleting a saved-creators group.',
            'js'      => true,
        ],
        'dd_msg_confirm_remove_creator' => [
            'label'   => 'Remove Creator Confirmation',
            'default' => 'Are you sure you want to remove this creator from the current group?',
            'group'   => 'confirm',
            'desc'    => 'Shown before removing a single creator from a group.',
            'js'      => true,
        ],
        'dd_msg_confirm_delete_saved_search' => [
            'label'   => 'Delete Saved Search Confirmation',
            'default' => 'Are you sure you want to permanently delete this saved search?',
            'group'   => 'confirm',
            'desc'    => 'Shown before deleting a saved search.',
            'js'      => true,
        ],
        'dd_msg_confirm_delete_note' => [
            'label'   => 'Delete Outreach Note Confirmation',
            'default' => 'Are you sure you want to permanently delete this note?',
            'group'   => 'confirm',
            'desc'    => 'Shown before deleting an outreach project note.',
            'js'      => true,
        ],

        // --- Success Notices ---------------------------------------------------------------
        'dd_msg_saved_success' => [
            'label'   => 'Creator Saved',
            'default' => 'Saved successfully!',
            'group'   => 'success',
            'desc'    => 'Returned after saving a creator to one or more lists.',
        ],
        'dd_msg_unsaved_success' => [
            'label'   => 'Creator Unsaved',
            'default' => 'Unsaved successfully!',
            'group'   => 'success',
            'desc'    => 'Returned after removing a creator from every list.',
        ],
        'dd_msg_search_saved_heading' => [
            'label'   => 'Search Saved Notice Heading',
            'default' => 'Search Saved',
            'group'   => 'success',
            'desc'    => 'Heading of the toast shown after saving a search.',
            'js'      => true,
        ],
        'dd_msg_search_saved_body' => [
            'label'   => 'Search Saved Notice Body',
            'default' => 'Your custom search has been successfully saved.',
            'group'   => 'success',
            'desc'    => 'Body of the toast shown after saving a search.',
            'js'      => true,
        ],
        'dd_msg_creator_removed' => [
            'label'   => 'Creator Removed',
            'default' => 'Creator removed successfully.',
            'group'   => 'success',
            'desc'    => 'Returned after removing a creator from a specific group.',
        ],
        'dd_msg_group_deleted' => [
            'label'   => 'Group Deleted',
            'default' => 'Group deleted.',
            'group'   => 'success',
            'desc'    => 'Returned after deleting a saved-creators group.',
        ],

        // --- Validation ---------------------------------------------------------------------
        'dd_msg_filter_required' => [
            'label'   => 'Required Filter Missing',
            'default' => 'Please populate all required filters (e.g., Location) before generating matches.',
            'group'   => 'validation',
            'desc'    => 'Shown when the filtered-search form is submitted without a required field.',
            'js'      => true,
        ],
        'dd_msg_group_name_required' => [
            'label'   => 'Group Name Required',
            'default' => 'Group name is required.',
            'group'   => 'validation',
            'desc'    => 'Shown when creating/editing a group without a name.',
            'js'      => true,
        ],
        'dd_msg_search_name_required' => [
            'label'   => 'Search Name Required',
            'default' => 'Please enter a name for your search.',
            'group'   => 'validation',
            'desc'    => 'Shown when confirming a saved search without a name.',
            'js'      => true,
        ],

        // --- Credit History Labels ---------------------------------------------------------
        'dd_msg_credit_log_unlock_influencer' => [
            'label'   => 'Transaction Type — Creator Unlock',
            'default' => 'Creator Unlock',
            'group'   => 'credit_log',
            'desc'    => 'Type badge / filter label for the credit-history ref "unlock_influencer" (the in-app unlock button).',
        ],
        'dd_msg_credit_log_buy_content' => [
            'label'   => 'Transaction Type — Creator Unlock (Store)',
            'default' => 'Creator Unlock',
            'group'   => 'credit_log',
            'desc'    => 'Type badge / filter label for the credit-history ref "buy_content" (myCred\'s own Sell Content button). Shares the same user-facing label as the in-app unlock — both mean the same thing to a member.',
        ],
        'dd_msg_credit_log_outreach_submission' => [
            'label'   => 'Transaction Type — Outreach Message',
            'default' => 'Outreach Message',
            'group'   => 'credit_log',
            'desc'    => 'Type badge / filter label for the credit-history ref "outreach_submission".',
        ],
        'dd_msg_credit_log_monthly_allowance' => [
            'label'   => 'Transaction Type — Monthly Allowance',
            'default' => 'Monthly Allowance',
            'group'   => 'credit_log',
            'desc'    => 'Type badge / filter label for the credit-history ref "pmpro_monthly_recurring".',
        ],
        'dd_msg_credit_log_registration' => [
            'label'   => 'Transaction Type — Plan Credits',
            'default' => 'Plan Credits',
            'group'   => 'credit_log',
            'desc'    => 'Type badge / filter label for the credit-history ref "pmpro_registration" — credits included with a paid plan, awarded on signup and topped up on upgrade (DD_PMPro_Rewards_Manager::award_registration_points()). Deliberately not called a "bonus": these are credits the member paid for as part of their plan, not a free extra.',
        ],
        'dd_msg_credit_log_credits_purchase' => [
            'label'   => 'Transaction Type — Credits Purchase',
            'default' => 'Credits Purchase',
            'group'   => 'credit_log',
            'desc'    => 'Type badge / filter label for the credit-history ref "buy_creds_with_stripe".',
        ],
        'dd_msg_credit_log_bank_transfer' => [
            'label'   => 'Transaction Type — Bank Transfer',
            'default' => 'Credits Purchase (Bank Transfer)',
            'group'   => 'credit_log',
            'desc'    => 'Type badge / filter label for the credit-history ref "buy_creds_with_bank_pending".',
        ],
        'dd_msg_credit_log_col_date' => [
            'label'   => 'Column Heading — Date',
            'default' => 'Date',
            'group'   => 'credit_log',
            'desc'    => 'Credit history table column heading.',
        ],
        'dd_msg_credit_log_col_type' => [
            'label'   => 'Column Heading — Type',
            'default' => 'Type',
            'group'   => 'credit_log',
            'desc'    => 'Credit history table column heading.',
        ],
        'dd_msg_credit_log_col_details' => [
            'label'   => 'Column Heading — Details',
            'default' => 'Transaction Details',
            'group'   => 'credit_log',
            'desc'    => 'Credit history table column heading.',
        ],
        'dd_msg_credit_log_col_amount' => [
            'label'   => 'Column Heading — Amount',
            'default' => 'Amount',
            'group'   => 'credit_log',
            'desc'    => 'Credit history table column heading.',
        ],
        'dd_msg_credit_log_col_balance' => [
            'label'   => 'Column Heading — Balance',
            'default' => 'Balance',
            'group'   => 'credit_log',
            'desc'    => 'Credit history table column heading for the running balance-after-transaction column.',
        ],
        'dd_msg_credit_log_empty' => [
            'label'   => 'Empty State',
            'default' => "You haven't used any credits yet. Unlock a creator or send a message to see your history here.",
            'group'   => 'credit_log',
            'desc'    => 'Shown in place of the table when a filtered (or unfiltered) credit history has no matching rows.',
        ],
        'dd_msg_credit_log_auth_required' => [
            'label'   => 'Login Required',
            'default' => 'Please log in to view your credit history.',
            'group'   => 'credit_log',
            'desc'    => 'Shown in place of the credit history widget for a logged-out visitor.',
        ],
        'dd_msg_credit_log_filter_all' => [
            'label'   => 'Filter — All',
            'default' => 'All',
            'group'   => 'credit_log',
            'desc'    => 'Label of the "show everything" segmented filter pill above the credit history table.',
        ],
        'dd_msg_credit_log_filter_spent' => [
            'label'   => 'Filter — Spent',
            'default' => 'Spent',
            'group'   => 'credit_log',
            'desc'    => 'Label of the "spend transactions only" segmented filter pill above the credit history table.',
        ],
        'dd_msg_credit_log_filter_earned' => [
            'label'   => 'Filter — Earned',
            'default' => 'Earned',
            'group'   => 'credit_log',
            'desc'    => 'Label of the "earn transactions only" segmented filter pill above the credit history table.',
        ],
        'dd_msg_credit_log_search_placeholder' => [
            'label'   => 'Search Field Placeholder',
            'default' => 'Search transactions…',
            'group'   => 'credit_log',
            'desc'    => 'Placeholder text of the free-text search box above the credit history table.',
        ],
        'dd_msg_credit_log_export_btn' => [
            'label'   => 'Export Button',
            'default' => 'Export CSV',
            'group'   => 'credit_log',
            'desc'    => 'Label of the credit history "download as CSV" button.',
        ],
        'dd_msg_credit_log_summary_balance' => [
            'label'   => 'Summary Tile — Balance',
            'default' => 'Current Balance',
            'group'   => 'credit_log',
            'desc'    => 'Heading of the credit history summary strip\'s balance tile.',
        ],
        'dd_msg_credit_log_summary_spent' => [
            'label'   => 'Summary Tile — Spent',
            'default' => 'Spent This Month',
            'group'   => 'credit_log',
            'desc'    => 'Heading of the credit history summary strip\'s monthly-spend tile.',
        ],
        'dd_msg_credit_log_summary_earned' => [
            'label'   => 'Summary Tile — Earned',
            'default' => 'Earned This Month',
            'group'   => 'credit_log',
            'desc'    => 'Heading of the credit history summary strip\'s monthly-earn tile.',
        ],
        'dd_msg_credit_log_summary_split' => [
            'label'   => 'Summary Tile — Unlocks vs. Messages',
            'default' => 'Unlocks vs. Messages',
            'group'   => 'credit_log',
            'desc'    => 'Heading of the credit history summary strip\'s tile splitting this month\'s spend between creator unlocks and outreach messages. Only shown when both currently cost credits — see the two labels below for when only one side does.',
        ],
        'dd_msg_credit_log_summary_unlocks_only' => [
            'label'   => 'Summary Tile — Unlocks Only',
            'default' => 'Creator Unlocks',
            'group'   => 'credit_log',
            'desc'    => 'Heading of the summary strip\'s single-stat tile shown instead of the split when outreach messages are currently free (Outreach Credit Cost is 0, or outreach isn\'t on this user\'s plan) — a free action is never logged, so comparing it would always read 0.',
        ],
        'dd_msg_credit_log_summary_messages_only' => [
            'label'   => 'Summary Tile — Messages Only',
            'default' => 'Messages Sent',
            'group'   => 'credit_log',
            'desc'    => 'Heading of the summary strip\'s single-stat tile shown instead of the split in the reverse case — creator unlocks are currently free but outreach messages still cost credits.',
        ],
        'dd_msg_credit_log_summary_purchased' => [
            'label'   => 'Summary Tile — Credits Purchased',
            'default' => 'Credits Purchased',
            'group'   => 'credit_log',
            'desc'    => 'Heading of the summary strip\'s lifetime "Credits Purchased" tile — total credits ever bought via Stripe or bank transfer. Not scoped to a calendar month like the other tiles, since it\'s an all-time total.',
        ],
        'dd_msg_credit_log_topup_intro' => [
            'label'   => 'Top-Up Banner — Intro',
            'default' => 'Your plan tops up your credits automatically, up to <strong>%s</strong> a month.',
            'group'   => 'credit_log',
            'desc'    => 'First sentence of the credit-history top-up banner. Keep the %s token — it\'s replaced with the plan\'s monthly allowance cap. Supports <strong> tags.',
            'html'    => true,
        ],
        'dd_msg_credit_log_topup_due' => [
            'label'   => 'Top-Up Banner — Amount Due',
            'default' => "You're eligible for up to <strong>%1\$s more</strong> %2\$s.",
            'group'   => 'credit_log',
            'desc'    => 'Second sentence of the top-up banner, shown when some allowance is still due. Keep both tokens — %1$s is the amount, %2$s is a ready-made "on [date]" / "soon" phrase. Supports <strong> tags.',
            'html'    => true,
        ],
        'dd_msg_credit_log_topup_at_cap' => [
            'label'   => 'Top-Up Banner — At Cap',
            'default' => "You're at your monthly cap for now — nothing more will be added until you use some of your current allowance.",
            'group'   => 'credit_log',
            'desc'    => 'Shown instead of the "amount due" sentence when the user hasn\'t used any of this cycle\'s allowance yet, so there\'s nothing left to top up.',
        ],
        'dd_msg_credit_log_topup_note' => [
            'label'   => 'Top-Up Banner — Reassurance Note',
            'default' => 'Unused credits never expire — they simply roll over.',
            'group'   => 'credit_log',
            'desc'    => 'Closing line of the top-up banner, always shown regardless of the branch above — the key point this banner exists to make.',
        ],
    ];
}

/**
 * Resolves an editable message's current value, falling back to its registry
 * default. Pass $args to interpolate %s-style tokens via vsprintf (e.g. the
 * unlocked-balance notice).
 *
 * @param string $key
 * @param array  $args
 * @return string
 */
function dd_get_message($key, $args = [])
{
    $definitions = dd_message_definitions();
    $default     = isset($definitions[$key]) ? $definitions[$key]['default'] : '';
    $value       = get_option($key, $default);

    if (! empty($args)) {
        return vsprintf($value, $args);
    }

    return $value;
}

/**
 * Returns only the messages flagged for front-end JS, keyed by option name,
 * for localization onto the `dd_messages` global.
 *
 * @return array<string, string>
 */
function dd_js_messages()
{
    $out = [];
    foreach (dd_message_definitions() as $key => $def) {
        if (! empty($def['js'])) {
            $out[$key] = dd_get_message($key);
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Tab registration — appends onto the Influencer Theme settings hub via the
// same dd_theme_settings_tabs filter every other module tab uses.
// ---------------------------------------------------------------------------
add_filter('dd_theme_settings_tabs', function ($tabs) {
    $tabs[] = [
        'id'     => 'messages',
        'label'  => 'Messages',
        'render' => 'dd_render_messages_tab_panel',
    ];
    return $tabs;
});

/**
 * Field renderer shared by every message setting — a text input or textarea
 * depending on the registry entry, plus its description.
 *
 * @param array $args
 */
function dd_render_message_field($args)
{
    $value = dd_get_message($args['key']);

    if (! empty($args['multiline'])) {
        echo '<textarea name="' . esc_attr($args['key']) . '" rows="3" class="large-text">' . esc_textarea($value) . '</textarea>';
    } else {
        echo '<input type="text" name="' . esc_attr($args['key']) . '" value="' . esc_attr($value) . '" class="large-text" />';
    }

    if (! empty($args['desc'])) {
        echo '<p class="description">' . esc_html($args['desc']) . '</p>';
    }
}

add_action('admin_init', function () {
    $definitions = dd_message_definitions();
    $groups = [
        'plan_gates'  => 'Plan & Upgrade Prompts',
        'dashboard'   => 'Dashboard Activity',
        'onboarding'  => 'Onboarding',
        'unlock'      => 'Unlock & Credit Confirmations',
        'confirm'     => 'Confirmation Dialogs',
        'success'     => 'Success Notices',
        'validation'  => 'Validation',
        'credit_log'  => 'Credit History Labels',
    ];

    foreach ($groups as $group_key => $group_label) {
        add_settings_section('dd_messages_section_' . $group_key, $group_label, '__return_false', 'dd-messages-settings');
    }

    foreach ($definitions as $key => $def) {
        register_setting('dd_messages_group', $key, [
            'type'              => 'string',
            'sanitize_callback' => function ($value) use ($def) {
                $value = (string) $value;

                // Blank means "reset to default" rather than persisting an empty notice.
                if (trim(wp_strip_all_tags($value)) === '') {
                    return $def['default'];
                }

                if (! empty($def['html'])) {
                    return wp_kses($value, ['strong' => [], 'br' => [], 'em' => [], 'b' => [], 'i' => []]);
                }

                if (! empty($def['multiline'])) {
                    return sanitize_textarea_field($value);
                }

                return sanitize_text_field($value);
            },
            'default' => $def['default'],
        ]);

        add_settings_field(
            $key,
            $def['label'],
            'dd_render_message_field',
            'dd-messages-settings',
            'dd_messages_section_' . $def['group'],
            [
                'key'       => $key,
                'multiline' => ! empty($def['multiline']),
                'desc'      => $def['desc'] ?? '',
            ]
        );
    }
});

/**
 * Self-contained "Messages" tab panel body (the hub provides the surrounding
 * `<div class="dd-panel">`).
 */
function dd_render_messages_tab_panel()
{
    if (! current_user_can('manage_options')) {
        return;
    }
?>
    <p class="dd-tab-desc">Edit the wording of notices, confirmations, and prompts shown to users across the site. Leaving a field blank and saving restores its default text.</p>
    <form action="options.php" method="post">
        <?php
        settings_fields('dd_messages_group');
        do_settings_sections('dd-messages-settings');
        submit_button('Save Messages');
        ?>
    </form>
<?php
}
