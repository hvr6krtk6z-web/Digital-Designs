document.querySelectorAll("[data-count]").forEach((counter) => {
	const field = document.getElementById(counter.dataset.count);
	const limit = Number(counter.dataset.limit);
	if (!field) return;
	const update = () => {
		counter.textContent = `${field.value.length} / ${limit}`;
		counter.classList.toggle("is-long", field.value.length > limit);
	};
	field.addEventListener("input", update);
	update();
});