const serviceSelect = document.querySelector("#service-select");
const calendarDays = document.querySelector("#calendar-days");
const calendarLabel = document.querySelector("#calendar-label");
const slotsContainer = document.querySelector("#time-slots");
const timesTitle = document.querySelector("#times-title");
const serviceDescription = document.querySelector("#service-description");
const message = document.querySelector("#booking-message");
const workflow = document.querySelector("#booking-workflow");
const customerPanel = document.querySelector("#customer-panel");
const bookingForm = document.querySelector("#booking-form");
const confirmation = document.querySelector("#booking-confirmation");
const monthFormatter = new Intl.DateTimeFormat(undefined, { month: "long", year: "numeric", timeZone: "UTC" });
const dateFormatter = new Intl.DateTimeFormat(undefined, { weekday: "long", month: "long", day: "numeric", timeZone: "UTC" });
const csrf = document.querySelector('meta[name="booking-csrf"]').content;
const queryService = new URLSearchParams(location.search).get("service_id");
let activeService = null;
let currentMonth = new Date();
currentMonth = new Date(Date.UTC(currentMonth.getFullYear(), currentMonth.getMonth(), 1));
let selectedDate = null;
let selectedSlot = null;
let availableDays = new Map();
let minDate = "";
let maxDate = "";
let requestSequence = 0;

function localDate(date) {
	return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, "0")}-${String(date.getUTCDate()).padStart(2, "0")}`;
}

function announce(text, isError = false) {
	message.textContent = text;
	message.classList.toggle("is-error", isError);
}

async function requestJson(url, options = {}) {
	const response = await fetch(url, { credentials: "same-origin", ...options });
	const data = await response.json();
	if (!response.ok) throw new Error(data.error || "The request could not be completed.");
	return data;
}

function resetSelection() {
	selectedDate = null;
	selectedSlot = null;
	customerPanel.hidden = true;
	confirmation.hidden = true;
	timesTitle.textContent = "Select a date first";
	slotsContainer.innerHTML = '<p class="empty-state">Available times will appear here.</p>';
}

function renderCalendar() {
	calendarLabel.textContent = monthFormatter.format(currentMonth);
	calendarDays.replaceChildren();
	const year = currentMonth.getUTCFullYear();
	const month = currentMonth.getUTCMonth();
	const firstWeekday = (new Date(Date.UTC(year, month, 1)).getUTCDay() + 6) % 7;
	for (let blank = 0; blank < firstWeekday; blank++) {
		const spacer = document.createElement("span");
		spacer.className = "calendar-blank";
		spacer.setAttribute("aria-hidden", "true");
		calendarDays.append(spacer);
	}
	const daysInMonth = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
	for (let day = 1; day <= daysInMonth; day++) {
		const date = `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
		const info = availableDays.get(date);
		const button = document.createElement("button");
		button.type = "button";
		button.className = "calendar-day";
		button.textContent = String(day);
		button.disabled = !info?.available || date < minDate || date > maxDate;
		button.setAttribute("aria-label", `${dateFormatter.format(new Date(`${date}T00:00:00Z`))}${info?.available ? `, ${info.slots} available times` : ", unavailable"}`);
		button.setAttribute("aria-pressed", String(date === selectedDate));
		if (button.disabled) button.classList.add("is-disabled");
		if (date === localDate(new Date())) button.classList.add("is-today");
		if (date === selectedDate) button.classList.add("is-selected");
		button.addEventListener("click", () => chooseDate(date));
		calendarDays.append(button);
	}
	const monthKey = localDate(currentMonth).slice(0, 7);
	document.querySelector("#previous-month").disabled = monthKey <= minDate.slice(0, 7);
	document.querySelector("#next-month").disabled = monthKey >= maxDate.slice(0, 7);
}

async function loadCalendar() {
	if (!activeService) return;
	const sequence = ++requestSequence;
	availableDays = new Map();
	calendarDays.innerHTML = '<span class="calendar-loading">Loading availability…</span>';
	try {
		const month = localDate(currentMonth).slice(0, 7);
		const data = await requestJson(`/api/calendar?service_id=${activeService.id}&month=${month}`);
		if (sequence !== requestSequence) return;
		minDate = data.min_date;
		maxDate = data.max_date;
		for (const day of data.days) availableDays.set(day.date, day);
		document.querySelector("#timezone-name").textContent = data.timezone;
		renderCalendar();
	} catch (error) {
		calendarDays.innerHTML = "";
		announce(error.message, true);
	}
}

async function chooseDate(date) {
	selectedDate = date;
	selectedSlot = null;
	customerPanel.hidden = true;
	const headingDate = dateFormatter.format(new Date(`${date}T00:00:00Z`));
	timesTitle.textContent = headingDate;
	slotsContainer.innerHTML = '<p class="empty-state">Checking available times…</p>';
	announce("");
	renderCalendar();
	const sequence = ++requestSequence;
	try {
		const data = await requestJson(`/api/slots?service_id=${activeService.id}&date=${date}`);
		if (sequence !== requestSequence) return;
		slotsContainer.replaceChildren();
		if (!data.slots.length) {
			slotsContainer.innerHTML = '<p class="empty-state">No times remain on this date. Choose another day.</p>';
			return;
		}
		for (const slot of data.slots) {
			const button = document.createElement("button");
			button.type = "button";
			button.className = "time-slot";
			button.textContent = slot.label;
			button.addEventListener("click", () => chooseSlot(slot, button));
			slotsContainer.append(button);
		}
	} catch (error) {
		slotsContainer.innerHTML = '<p class="empty-state">Availability could not be loaded.</p>';
		announce(error.message, true);
	}
}

function chooseSlot(slot, button) {
	selectedSlot = slot;
	slotsContainer.querySelectorAll(".time-slot").forEach((item) => {
		item.classList.remove("is-selected");
		item.setAttribute("aria-pressed", "false");
	});
	button.classList.add("is-selected");
	button.setAttribute("aria-pressed", "true");
	document.querySelector("#appointment-summary").textContent = `${activeService.name} · ${dateFormatter.format(new Date(`${selectedDate}T00:00:00Z`))} · ${slot.label}`;
	customerPanel.hidden = false;
	customerPanel.scrollIntoView({ behavior: "smooth", block: "start" });
	bookingForm.elements.name.focus({ preventScroll: true });
}

function makeCalendarFile(data) {
	const escapeIcs = (value) => value.replace(/\\/g, "\\\\").replace(/,/g, "\\,").replace(/;/g, "\\;").replace(/\n/g, "\\n");
	const stamp = (value) => value.replace(/[-:]/g, "").replace(/\.\d+Z$/, "Z");
	const ics = [
		"BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Fieldnote Booking//Appointment//EN", "BEGIN:VEVENT",
		`UID:${escapeIcs(data.reference)}@fieldnote-booking`, `DTSTAMP:${stamp(new Date().toISOString())}`,
		`DTSTART:${stamp(data.starts_at)}`, `DTEND:${stamp(data.ends_at)}`,
		`SUMMARY:${escapeIcs(data.service)}`, `DESCRIPTION:${escapeIcs(`Booking reference: ${data.reference}`)}`,
		"END:VEVENT", "END:VCALENDAR",
	].join("\r\n");
	const link = document.querySelector("#calendar-download");
	link.href = URL.createObjectURL(new Blob([ics], { type: "text/calendar;charset=utf-8" }));
}

serviceSelect.addEventListener("change", () => {
	activeService = services.find((service) => String(service.id) === serviceSelect.value) || null;
	serviceDescription.textContent = activeService ? `${activeService.description}${activeService.description ? " · " : ""}${activeService.duration_minutes} minutes` : "";
	resetSelection();
	loadCalendar();
});

document.querySelector("#previous-month").addEventListener("click", () => {
	currentMonth = new Date(Date.UTC(currentMonth.getUTCFullYear(), currentMonth.getUTCMonth() - 1, 1));
	resetSelection();
	loadCalendar();
});
document.querySelector("#next-month").addEventListener("click", () => {
	currentMonth = new Date(Date.UTC(currentMonth.getUTCFullYear(), currentMonth.getUTCMonth() + 1, 1));
	resetSelection();
	loadCalendar();
});

bookingForm.addEventListener("submit", async (event) => {
	event.preventDefault();
	if (!selectedSlot || !selectedDate || !activeService) {
		announce("Choose a date and time before continuing.", true);
		return;
	}
	if (!bookingForm.reportValidity()) return;
	const button = document.querySelector("#submit-booking");
	button.disabled = true;
	button.textContent = "Confirming…";
	announce("");
	const formData = new FormData(bookingForm);
	const payload = Object.fromEntries(formData.entries());
	payload.service_id = activeService.id;
	payload.date = selectedDate;
	payload.starts_at = selectedSlot.starts_at;
	try {
		const data = await requestJson("/api/bookings", {
			method: "POST",
			headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
			body: JSON.stringify(payload),
		});
		document.querySelector("#confirmation-summary").textContent = `${data.service} · ${dateFormatter.format(new Date(`${data.date}T00:00:00Z`))} · ${data.time} (${data.timezone})`;
		document.querySelector("#confirmation-reference").textContent = data.reference;
		makeCalendarFile(data);
		workflow.hidden = true;
		customerPanel.hidden = true;
		confirmation.hidden = false;
		confirmation.focus();
	} catch (error) {
		announce(error.message, true);
		if (error.message.includes("just taken")) {
			await chooseDate(selectedDate);
		}
	} finally {
		button.disabled = false;
		button.innerHTML = 'Confirm appointment <span aria-hidden="true">↗</span>';
	}
});

let services = [];
async function initializeBooking() {
	try {
		const data = await requestJson("/api/services");
		if (!data.enabled || !data.services.length) {
			workflow.hidden = true;
			document.querySelector("#booking-unavailable").hidden = false;
			return;
		}
		services = data.services;
		serviceSelect.replaceChildren();
		for (const service of services) {
			const option = document.createElement("option");
			option.value = service.id;
			option.textContent = `${service.name} · ${service.duration_minutes} min`;
			serviceSelect.append(option);
		}
		const initialService = services.find((service) => String(service.id) === queryService) || services[0];
		serviceSelect.value = initialService.id;
		serviceSelect.dispatchEvent(new Event("change"));
	} catch (error) {
		workflow.hidden = true;
		document.querySelector("#booking-unavailable").hidden = false;
		announce(error.message, true);
	}
}

initializeBooking();