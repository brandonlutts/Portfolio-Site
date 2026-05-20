(() => {
  const RECAPTCHA_SITE_KEY = "6LdokoksAAAAAD4vdtgnslez30sz1P8pI_Tbgt-v";
  const forms = document.querySelectorAll('form[action="/assets/forms/submit.php"]');
  if (!forms.length) return;

  function ensureHidden(form, name, value = "") {
    let input = form.querySelector(`input[name="${name}"]`);
    if (!input) {
      input = document.createElement("input");
      input.type = "hidden";
      input.name = name;
      form.appendChild(input);
    }
    input.value = value;
    return input;
  }

 function getFormAction(form) {
  const formName = form.querySelector('input[name="form-name"]')?.value || "contact_form_submit";
  return `submit_${formName}`
    .toLowerCase()
    .replace(/-/g, "_")
    .replace(/[^a-z0-9_/]/g, "_");
}

  function getStatusBox(form) {
    let box = form.querySelector("[data-form-status]");
    if (!box) {
      box = document.createElement("div");
      box.setAttribute("data-form-status", "");
      box.className = "panel";
      box.style.marginTop = "10px";
      box.style.padding = "12px";
      box.style.display = "none";
      form.appendChild(box);
    }
    return box;
  }

  function setStatus(box, msg, isError = false) {
    box.style.display = "block";
    box.style.border = "1px solid " + (
      isError
        ? "rgba(255,120,120,.35)"
        : "rgba(120,255,190,.28)"
    );

    box.innerHTML = `
      <div class="kicker">${isError ? "ERROR" : "TRANSMISSION"}</div>
      <div style="font-weight:800;margin-top:6px">
        ${isError ? "Not sent." : "Sent successfully."}
      </div>
      <div style="color:var(--muted);margin-top:6px;line-height:1.6">
        ${msg}
      </div>
    `;
  }

  async function getRecaptchaToken(action) {
    if (!window.grecaptcha || !RECAPTCHA_SITE_KEY) {
      throw new Error("reCAPTCHA not loaded.");
    }

    await new Promise((resolve) => window.grecaptcha.ready(resolve));
    return window.grecaptcha.execute(RECAPTCHA_SITE_KEY, { action });
  }

  forms.forEach((form) => {
    const statusBox = getStatusBox(form);

    ensureHidden(form, "form_started", String(Math.floor(Date.now() / 1000)));
    ensureHidden(form, "recaptcha_token", "");
    ensureHidden(form, "recaptcha_action", "");

    form.addEventListener("submit", async (e) => {
      if (!window.fetch) return;
      e.preventDefault();

      const btn = form.querySelector('button[type="submit"], input[type="submit"]');
      btn?.setAttribute("disabled", "disabled");
      setStatus(statusBox, "Running security checks and sending…");

      try {
        const action = getFormAction(form);
        const token = await getRecaptchaToken(action);

        form.querySelector('input[name="recaptcha_token"]').value = token;
        form.querySelector('input[name="recaptcha_action"]').value = action;

        const formData = new FormData(form);

        const res = await fetch(form.action, {
          method: "POST",
          body: formData,
          headers: { Accept: "application/json" }
        });

        const data = await res.json().catch(() => ({}));

        if (!res.ok || !data.ok) {
          setStatus(statusBox, data.message || "Something went wrong. Please try again.", true);
          btn?.removeAttribute("disabled");
          return;
        }

        setStatus(statusBox, data.message || "Your message has been sent.");
        form.reset();

        ensureHidden(form, "form_started", String(Math.floor(Date.now() / 1000)));
        ensureHidden(form, "recaptcha_token", "");
        ensureHidden(form, "recaptcha_action", "");

        btn?.removeAttribute("disabled");
      } catch (err) {
        setStatus(statusBox, "Security check failed or network error. Please try again.", true);
        btn?.removeAttribute("disabled");
      }
    });
  });
})();