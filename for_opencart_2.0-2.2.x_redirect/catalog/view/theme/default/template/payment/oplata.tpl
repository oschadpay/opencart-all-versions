<?php if($oschadpay_data['result'] === false) {?>
<div class="alert alert-warning">
			<?php echo $oschadpay_data['message']; ?>
        <button type="button" class="close" data-dismiss="alert">×</button>
</div>
<?php }else{?>
<div class="buttons">
        <div class="pull-right">
            <a href="<?php echo $oschadpay_data['url']; ?>" class="btn btn-primary"><?php echo $button_confirm; ?></a>
        </div>
</div>
<?php }?>