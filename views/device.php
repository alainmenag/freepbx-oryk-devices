<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">
			<form
				class="fpbx-submit"
				name="submitSettings"
				action="config.php?display=oryk_devices&action=view" 
				method="post"
				data-fpbx-delete="config.php?display=oryk_devices&amp;id=<?= $id ?>&amp;action=del"
			>

				<input type="hidden" name="action" value="setkey">

				<div class="section-title" data-for="AdvancedSettingsDetails" style="padding: 0;">
					<h2>
						<i class="fa fa-minus"></i>
						<span class="title">Device</span>
					</h2>
				</div>

				<div class="section" data-id="AdvancedSettingsDetails" style="">

					<?php
						foreach ($sets as $key => $set) {
							$value = ($set['value'] ?? $set['default'] ?? '');
							$title = ($set['title'] ?? '');
							$type = ($set['type'] ?? '');
							$example = ($set['example'] ?? '');

							if ($type === 'hidden') { ?>
								<input type="hidden"
									id="<?php echo $key; ?>"
									name="<?php echo $key; ?>"
									value="<?php echo htmlspecialchars((string) $value); ?>"
								/>
							<?php  continue; }
					?>
						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-7">

										<?php if ($title): ?>
											<label class="control-label" for="<?php echo $key; ?>"><?php echo $title ?></label>
										<?php endif; ?>

										<?php if (isset($set['help'])): ?>
											<i class="fa fa-question-circle fpbx-help-icon"
												data-for="<?php echo $key; ?>"></i>
										<?php endif; ?>

										<a
											href="#"
											data-for="<?php echo $key; ?>"
											data-type="<?php echo $type; ?>"
											data-defval="<?php echo ($set['default'] ?? ''); ?>" class="hidden defset">
											<i class="fa fa-refresh"></i>
										</a>

									</div>
									<div class="col-md-5 text-right <?php echo ($set['disabled'] ?? false) ? 'disable' : ''; ?>">

										<?php if ($type === 'boolean'): ?>
											<div class="radioset">
												<input
													type="hidden"
													class=""
													id="<?php echo $key; ?>default"
													name="<?php echo $key; ?>default"
													value="<?php echo ($set['default'] ?? ''); ?>"
												/>
												<input
													type="radio"
													class=""
													id="<?php echo $key; ?>true"
													name="<?php echo $key; ?>"
													value="true" <?php echo (($set['default'] ?? '') ? 'checked=""' : ''); ?>
												/>
												<label for="<?php echo $key; ?>true">Yes</label>
												<input
													type="radio"
													class=""
													id="<?php echo $key; ?>false"
													name="<?php echo $key; ?>"
													value="false" <?php echo ((!$set['default'] ?? '') ? 'checked=""' : ''); ?>
												/>
												<label for="<?php echo $key; ?>false">No</label>
											</div>
										<?php elseif ($type === 'link'): ?>
											<a href="<?php echo ($set['default'] ?? ''); ?>" target="_blank" class="btn btn-default">Manage</a>
										<?php elseif ($type): ?>
											<input type="<?php echo $type; ?>" class="form-control"
												id="<?php echo $key; ?>"
												name="<?php echo $key; ?>"
												placeholder="<?php echo ($set['placeholder'] ?? ''); ?>"
												value="<?php echo htmlspecialchars((string) $value); ?>"
												<?php echo ($set['disabled'] ?? false) ? 'disabled' : ''; ?>
											/>
											<?php if ($example): ?>
												<p class="help-block">e.g. <?php echo $example; ?></p>
											<?php endif; ?>
										<?php endif; ?>

									</div>
								</div>
							</div>
							<?php if (isset($set['help'])): ?>
								<div class="row">
									<div class="col-md-12">
										<span
											id="<?php echo $key; ?>-help"
											class="help-block fpbx-help-block"
										><?php echo $set['help']; ?></span>
									</div>
								</div>
							<?php endif; ?>
							<?php if (isset($set['html'])): ?>
								<?php echo $set['html']; ?>
							<?php endif; ?>
						</div>
					<?php } ?>

				</div>

			</form>
		</div>
	</div>
</div>

<script>

	$(document).on('click', '[name="close"]', function() {
		window.location.search = '?display=oryk_devices&action=list';
	});

</script>