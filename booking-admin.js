const adminCsrf = document.querySelector('meta[name="booking-csrf"]')?.content || "";

document.querySelectorAll(".reschedule-form").forEach((form) => {
	const dateInput = form.querySelector('input[name="date"]');
	const timeSelect = form.querySelector('select[name="starts_at"]');
	dateInput.addEventListener("change", async () => {
		timeSelect.disabled = true;
		timeSelect.replaceChildren(new Option("Loading times…", ""));
		if (!dateInput.value) return;
		try {
			const params = new URLSearchParams({
				service_id: form.dataset.serviceId,
				date: dateInput.value,
				exclude_id: form.dataset.bookingId,
			});
			const response = await fetch(`/api/admin/slots?${params}`, { credentials: "same-origin" });
			const data = await response.json();
			if (!response.ok) throw new Error(data.error || "Could not load times.");
			timeSelect.replaceChildren(new Option(data.slots.length ? "Choose a time" : "No times available", ""));
			for (const slot of data.slots) timeSelect.add(new Option(slot.label, slot.starts_at));
			timeSelect.disabled = data.slots.length === 0;
		} catch (error) {
			timeSelect.replaceChildren(new Option(error.message, ""));
		}
	});
});