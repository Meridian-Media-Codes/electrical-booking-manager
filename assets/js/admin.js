document.addEventListener('DOMContentLoaded', function () {
	const selectAll = document.getElementById('ebm-select-all-customers');

	if (selectAll) {
		selectAll.addEventListener('change', function () {
			document.querySelectorAll('.ebm-customer-check').forEach(function (checkbox) {
				checkbox.checked = selectAll.checked;
			});
		});
	}
});
