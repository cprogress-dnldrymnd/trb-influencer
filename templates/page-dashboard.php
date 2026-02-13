<?php
/*-----------------------------------------------------------------------------------*/
/* Template Name: Dashboard
/*-----------------------------------------------------------------------------------*/
?>
<?php get_header() ?>
<?php while (have_posts()) { ?>
    <?php the_post(); ?>
    <div class="dashboard">
        <div class="dashboard-header">
            <?= do_shortcode('[elementor-template id="1571"]') ?>
            <div class="close-mobile-nav mobile-nav-trigger"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x" viewBox="0 0 16 16">
                    <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708" />
                </svg></div>
        </div>
        <div class="dashboard-content">
            <?= do_shortcode('[elementor-template id="1640"]') ?>
            <div class="dashboard-content-inner">
                <?php the_content() ?>
            </div>
        </div>
    </div>
    <div class="custom-backdrop mobile-nav-trigger">

    </div>
<?php } ?>
<?php get_footer() ?>