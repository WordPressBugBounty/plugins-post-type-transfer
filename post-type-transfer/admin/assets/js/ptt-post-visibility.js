(function () {
	const checkAll = document.getElementById('ptt-check-all');
	if (!checkAll) {
		return;
	}

	const options = document.querySelectorAll('.ptt-visibility-option');

	function syncCheckAll() {
		const checked = document.querySelectorAll('.ptt-visibility-option:checked');
		checkAll.checked = checked.length === options.length;
		checkAll.indeterminate = checked.length > 0 && checked.length < options.length;
	}

	checkAll.addEventListener('change', function () {
		options.forEach(function (checkbox) {
			checkbox.checked = checkAll.checked;
		});
	});

	options.forEach(function (checkbox) {
		checkbox.addEventListener('change', syncCheckAll);
	});

	syncCheckAll();
}());
