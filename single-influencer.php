<?php get_header() ?>
<?php while(have_posts()) { ?>
<?php the_post(); ?>
        <?= do_shortcode('[mycred_sell_this] [elementor-template id="2442"] [/mycred_sell_this]') ?>

<?php }?>
<?php get_footer() ?>

