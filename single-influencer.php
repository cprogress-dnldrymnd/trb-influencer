<?php get_header() ?>
<?php while(have_posts()) { ?>
<?php the_post(); ?>
<div class="dashboard">
    <div class="dashboard-header">
        <?= do_shortcode('[elementor-template id="1571"]') ?>
    </div>
    <div class="dashboard-content">
        <?= do_shortcode('[elementor-template id="1640"]') ?>
        <?php// do_shortcode('[elementor-template id="1868"]') ?> 

        <?= do_shortcode('[mycred_sell_this] [elementor-template id="2442"] [/mycred_sell_this]') ?>
    </div>
</div>
<?php }?>
<?php get_footer() ?>

