<?php get_header() ?>
<?php while(have_posts()) { ?>
<?php the_post(); ?>
<div class="dashboard">
   <?= do_shortcode('[elementor-template id="2483"]') ?> 
</div>
<?php }?>
<?php get_footer() ?>

