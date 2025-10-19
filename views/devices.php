<style>
	.flex {
		display: flex;
	}
	.gap-3 {
		gap: 3px;
	}
</style>

<script>

	const types = <?php echo json_encode($types); ?>;

	function formatUser(value, row) {
		return value ? `<a href="?display=extensions&extdisplay=${value}">${value}</a>` : '-';
	}

	function formatActions(value, row, index, heading) {
		const type = types[row.kind] || {};
		return [
			`<div class="flex gap-3">`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_devices&action=view&id=${row.id}" role="button">Edit</a>`,
			type?.actions?.restart ? `<button class="btn btn-primary btn-sm fa fa-refresh" name="restart" value="${row.id}"></button>` : '',
			row.link ? `<a class="btn btn-secondary btn-sm" href="${row.link}" target="${row.target}" role="button">Link</a>` : '',
			`</div>`
		].join('');
	}

	function formatKind(value, row) {
		return (types[value] || {}).title || (value || '').toUpperCase();
	}

	$(document).on('click', '[name="new"]', function () {
		window.location.search = '?display=oryk_devices&action=view';
	});

	$(document).on('click', '[name="refresh"]', function () {
		$('#device_table').bootstrapTable('refresh');
	});

	$(document).on('click', '[name="restart"]', function () {
		const req = $.post('ajax.php?module=oryk_devices&command=restart', { id: $(this).val() });

		notie.alert(4, "Restarted.", 3);
	});

</script>

<div class="container-fluid">
	<div class="fpbx-container">

		<table
			id="device_table"
			data-toggle="table"
			data-url="ajax.php?module=oryk_devices&command=list"
			class="table table-striped" data-side-pagination="server" data-pagination="true" data-search="true"
			data-sort-name="user"
			data-sort-order="asc">
			<thead>
				<tr>
					<th data-field="user"data-formatter="formatUser" data-sortable="true">Extension</th>
					<th data-field="description" data-sortable="true">Description</th>
					<th data-field="kind" data-formatter="formatKind" data-sortable="true">Kind</th>
					<th data-field="actions" data-formatter="formatActions">Actions</th>
				</tr>
			</thead>
		</table>

	</div>
</div>