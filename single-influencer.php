<?php get_header() ?>
<?php while(have_posts()) { ?>
<?php the_post(); ?>
<div class="dashboard">
    <div class="dashboard-header">
        <?= do_shortcode('[elementor-template id="1571"]') ?>
    </div>
    <div class="dashboard-content">
        <?= do_shortcode('[elementor-template id="1640"]') ?>
        <?= do_shortcode('[elementor-template id="1868"]') ?> 
    </div>
</div>
<?php }?>
<?php get_footer() ?>

