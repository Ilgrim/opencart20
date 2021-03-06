<?php

    /** @var Loader $this */
    /** @var ModelSmart2payHelper $model_smart2pay_helper */
    /** @property Registry $registry */
    // $this->model( 'smart2pay/helper' );
    // $model_smart2pay_helper = $this->registry->get( 'model_smart2pay_helper' );

    global $loader;

    $loader->model( 'smart2pay/helper' );
    $model_smart2pay_helper = ModelSmart2payHelper::get_last_instance();

    echo $header;
    echo $column_left;
?>

<div id="content">
    <div class="page-header">
        <div class="container-fluid">
            <div class="pull-right">
                <button type="submit" form="form" data-toggle="tooltip" title="<?php echo $btn_text_save; ?>" class="btn btn-primary"><i class="fa fa-save"></i></button>
                <a href="<?php echo $cancel; ?>" data-toggle="tooltip" title="<?php echo $btn_text_cancel; ?>" class="btn btn-default"><i class="fa fa-reply"></i></a>
            </div>
            <h1><?php echo $heading_title; ?></h1>
            <ul class="breadcrumb">
                <?php foreach ($breadcrumbs as $breadcrumb) { ?>
                <li><a href="<?php echo $breadcrumb['href']; ?>"><?php echo $breadcrumb['text']; ?></a></li>
                <?php } ?>
            </ul>
        </div>
    </div>

    <div class="container-fluid">
        <?php if ($error_warning) { ?>
        <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?php echo $error_warning; ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php } ?>
        <?php if ($success) { ?>
            <div class="alert alert-success"><i class="fa fa-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php } ?>
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-pencil"></i> <?php echo $text_edit; ?></h3>
            </div>
            <?php
                $tabs_arr = array();
                $tabs_arr['current_tab'] = 'module_settings';

                echo $model_smart2pay_helper->render_main_plugin_tabs( $tabs_arr );
            ?>
            <div class="panel-body">
            <form action="<?php echo $action; ?>" method="post" enctype="multipart/form-data" id="form" class="form-horizontal">
            <?php
                echo $model_smart2pay_helper->render_module_fields( $form_elements, $error );
            ?>
            </form>
            </div>
        </div>
    </div>
</div>

<?php echo $footer; ?>
