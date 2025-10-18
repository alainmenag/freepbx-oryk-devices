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
		return value || '-';
	}

	function formatActions(value, row) {
		return [
			`<div class="flex gap-3">`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_devices&action=view&id=${row.id}" role="button">Edit</a>`,
			row.link ? `<a class="btn btn-secondary btn-sm" href="${row.link}" target="${row.target}" role="button">Link</a>` : '',
			`</div>`
		].join('');
	}

	function formatKind(value, row) {
		const type = types[value] || {};

		return type.title || vaue.toUpperCase();
	}

	$(document).on('click', '[name="new"]', function () {
		window.location.search = '?display=oryk_devices&action=view';
	});

	$(document).on('click', '[name="refresh"]', function () {
		$('#device_table').bootstrapTable('refresh');
	});

</script>

<div class="container-fluid">
	<div class="fpbx-container">

		<table
			id="device_table"
			data-toggle="table"
			data-url="ajax.php?module=oryk_devices&command=getDevices"
			class="table table-striped" data-side-pagination="server" data-pagination="true" data-search="true"
			data-sort-name="id" data-sort-order="asc">
			<thead>
				<tr>
					<th data-field="description" data-sortable="true">Description</th>
					<th data-field="user"data-formatter="formatUser" data-sortable="true">User</th>
					<th data-field="kind" data-formatter="formatKind" data-sortable="true">Kind</th>
					<th data-field="actions" data-formatter="formatActions">Actions</th>
				</tr>
			</thead>
		</table>

	</div>
</div>