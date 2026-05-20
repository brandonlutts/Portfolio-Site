  document.addEventListener("DOMContentLoaded", () => {
    const yearElement = document.getElementById("y");
    if (yearElement) yearElement.textContent = new Date().getFullYear();
  });

  (() => {
  // Runs safely even if included on pages without FAQ
  const root = document.querySelector(".faq-cube");
  if (!root) return;

  const items = Array.from(root.querySelectorAll("[data-faq-entry]"));
  const search = document.querySelector("[data-faq-search]");

  // ---------
  // 1) Make every accordion input/label pair valid (unique IDs)
  // ---------
  items.forEach((item, i) => {
    const input = item.querySelector("input");
    const label = item.querySelector(".cube-faq-header");

    if (!input || !label) return;

    // If you kept radios and want ONLY one open at a time,
    // keep input.type = "radio" and set the same name.
    // If you changed to checkboxes, each is independent.
    //
    // OPTION A (multiple open): checkbox
    // input.type = "checkbox";

    // OPTION B (single open): radio
    // input.type = "radio";
    // input.name = "faq";

    // Always generate unique id + wire label correctly
    const id = `faq-item-${i + 1}`;
    input.id = id;
    label.setAttribute("for", id);

    // Only the first item starts open (optional)
    if (i === 0) input.checked = true;
    else input.checked = false;
  });

  // ---------
  // 2) Search / filter (and collapse hidden ones so you don't get "open but invisible" states)
  // ---------
  const normalize = (s) => (s || "").toLowerCase();

  function applyFilter(qRaw) {
    const q = normalize(qRaw);

    items.forEach((item) => {
      const text = normalize(item.dataset.faqText || item.textContent);
      const match = !q || text.includes(q);

      item.style.display = match ? "" : "none";

      if (!match) {
        const input = item.querySelector("input");
        if (input) input.checked = false;
      }
    });
  }

  if (search) {
    search.addEventListener("input", () => applyFilter(search.value));
  }
})();
