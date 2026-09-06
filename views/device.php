<style>
	.p15 {
		padding: 15px;
	}
	.flex {
		display: flex;
		align-items: center;
	}
	.flex-stretch {
		align-items: stretch;
	}
</style>

<form
	class="fpbx-submit container-fluid"
	name="submitSettings"
	action="config.php?display=oryk_connect&action=view" 
	method="post"
	data-fpbx-delete="config.php?display=oryk_connect&amp;id=<?= $file['id'] ?? '' ?>&amp;action=del"
	style="
		background-color: transparent;
	"
>
	<input type="hidden" name="action" value="setkey">

	<?php if (!empty($error)): ?>
		<div class="p15">
			<div class="alert alert-danger" role="alert">
				<i class="fa fa-exclamation-circle"></i>
				<?= htmlspecialchars((string) $error) ?>
			</div>
		</div>
	<?php endif; ?>

	<?php foreach ($file ?? [] as $key => $fields): ?>
		<?php if ($groups[$key] ?? null): ?>
			<div class="fpbx-container p15">
				<div class="display full-border">
					<div class="section-title" data-for="device_<?php echo $key; ?>" style="padding: 0;">
						<h2>
							<i class="fa fa-minus"></i>
							<span class="title"><?php echo $groups[$key]['title'] ?? $key ?></span>
						</h2>
					</div>
					<div class="section" data-id="device_<?php echo $key; ?>" style="">
						<?php include 'fields.php'; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>
	<?php endforeach; ?>

</form>

<script>

	$(document).on('click', '[name="new"]', function () {
		window.location.search = '?display=oryk_connect&action=view';
	});

	$(document).on('click', '[name="close"]', function() {
		window.location.search = '?display=oryk_connect&action=list';
	});

</script>