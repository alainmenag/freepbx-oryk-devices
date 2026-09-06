<?php
	foreach ($fields as $key => $set) {
		$value = ($set['value'] ?? $set['default'] ?? '');
		$title = ($set['title'] ?? '');
		$type = ($set['type'] ?? '');
		$example = ($set['example'] ?? '');

		if ($type === 'html') { ?>
			<?php echo $set['html'] ?? ''; ?>
		<?php  continue; }

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
						<label class="control-label" for="<?php echo $key; ?>">
							<?php echo $title ?>
							<?php if ($set['required'] ?? false): ?>
								<span class="text-danger" title="Required">*</span>
							<?php endif; ?>
						</label>
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
					<?php elseif ($type === 'span'): ?>
						<span class="no-scroll"><?php echo ($set['value'] ?? ''); ?></span>
					<?php elseif ($type === 'link'): ?>
						<a href="<?php echo ($set['default'] ?? ''); ?>" target="_blank" class="btn btn-default">Manage</a>
					<?php elseif ($type === 'select'): ?>
						<select
							class="form-control"
							id="<?php echo $key; ?>"
							name="<?php echo $key; ?>"
							<?php echo ($set['disabled'] ?? false) ? 'disabled' : ''; ?>
							<?php echo ($set['required'] ?? false) ? 'required' : ''; ?>
						>
							<?php
								if (isset($set['options']) && is_array($set['options'])) {
									foreach ($set['options'] as $opt_key => $opt) {
										$selected = ($opt_key == $value) ? 'selected' : '';
										echo "<option value=\"" . $opt_key . "\" $selected>" . ($opt['title'] ?? $opt_key) . "</option>";
									}
								}
							?>
						</select>
					<?php elseif ($type): ?>
						<div class="flex flex-stretch">
							<input type="<?php echo $type; ?>" class="form-control"
								id="<?php echo $key; ?>"
								name="<?php echo $key; ?>"
								placeholder="<?php echo htmlspecialchars((string) ($set['placeholder'] ?? '')); ?>"
								value="<?php echo htmlspecialchars((string) $value); ?>"
								<?php echo ($set['disabled'] ?? false) ? 'disabled' : ''; ?>
								<?php echo ($set['required'] ?? false) ? 'required' : ''; ?>
							/>
							<?php if ($type === 'password'): ?>
								<button
									onclick="<?php echo $key; ?>.type = <?php echo $key; ?>.type === 'text' ? 'password' : 'text'; event.target.innerText = <?php echo $key; ?>.type === 'text' ? 'Hide' : 'Show';"
									type="button"
									style="border-width: 1px; border-left: none;"
								>Show</button>
							<?php endif; ?>
						</div>
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
		<?php if ($set['disabled'] ?? false): ?>
			<input type="hidden" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars((string) $value); ?>"/>
		<?php endif; ?>
	</div>
<?php } ?>