<?php get_header() ?>
<?php while(have_posts()) { ?>
<?php the_post(); ?>
<div class="dashboard">
    <div class="dashboard-header">
        <?= do_shortcode('[elementor-template id="' . dd_get_template_id('dd_tpl_header_nav', 1571) . '"]') ?>
    </div>
    <div class="dashboard-content">
        <?= do_shortcode('[elementor-template id="' . dd_get_template_id('dd_tpl_dashboard_content', 1640) . '"]') ?>
        <?= do_shortcode('[elementor-template id="' . dd_get_template_id('dd_tpl_single_influencer', 1868) . '"]') ?>
    </div>
</div>
<?php }?>
<?php get_footer() ?>
