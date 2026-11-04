// Mobile menu toggle
const menuToggle = document.getElementById("menu-toggle");
const navLinks = document.getElementById("nav-links");

menuToggle.addEventListener("click", () => {
  const isOpen = navLinks.classList.toggle("active");
  menuToggle.setAttribute("aria-expanded", isOpen);
});

// Close the menu after tapping a link
navLinks.querySelectorAll("a").forEach((link) => {
  link.addEventListener("click", () => {
    navLinks.classList.remove("active");
    menuToggle.setAttribute("aria-expanded", "false");
  });
});

// Contact form validation
const form = document.getElementById("contact-form");
const statusMsg = document.getElementById("form-status");

function setError(input, message) {
  const group = input.closest(".form-group");
  group.classList.add("invalid");
  group.querySelector(".error-msg").textContent = message;
}

function clearError(input) {
  const group = input.closest(".form-group");
  group.classList.remove("invalid");
  group.querySelector(".error-msg").textContent = "";
}

function validateField(input) {
  const value = input.value.trim();

  if (value === "") {
    setError(input, "This field is required.");
    return false;
  }

  if (input.id === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
    setError(input, "Enter a valid email address.");
    return false;
  }

  if (input.id === "phone") {
    const digits = value.replace(/[\s-]/g, "");
    if (!/^(\+?234|0)\d{10}$/.test(digits)) {
      setError(input, "Enter a valid phone number, e.g. 0706 389 5975.");
      return false;
    }
  }

  clearError(input);
  return true;
}

const fields = form.querySelectorAll(".form-group input, .form-group textarea");

// Clear the error as the user fixes a field
fields.forEach((field) => {
  field.addEventListener("input", () => {
    if (field.closest(".form-group").classList.contains("invalid")) {
      validateField(field);
    }
  });
});

let statusTimer;

function showStatus(text, isError = false) {
  clearTimeout(statusTimer);
  statusMsg.style.color = isError ? "#d93025" : "";
  statusMsg.textContent = text;
  statusTimer = setTimeout(() => {
    statusMsg.textContent = "";
  }, 6000); // 6000 = 6 seconds
}

form.addEventListener("submit", async (e) => {
  e.preventDefault();
  clearTimeout(statusTimer);
  statusMsg.textContent = "";

  let allValid = true;
  fields.forEach((field) => {
    if (!validateField(field)) allValid = false;
  });

  if (!allValid) {
    form.querySelector(".invalid input, .invalid textarea").focus();
    return;
  }

  const btn = form.querySelector(".submit-btn");
  btn.disabled = true;
  btn.textContent = "Sending...";

  try {
    const res = await fetch("save-contact.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(Object.fromEntries(new FormData(form))),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || "Something went wrong.");

    showStatus("Thank you! We have received your message and will contact you soon.");
    form.reset();
  } catch (err) {
    showStatus("Sorry, your message could not be sent. Please try again.", true);
  } finally {
    btn.disabled = false;
    btn.textContent = "Send Message";
  }
});

// Product image sliders
document.querySelectorAll(".product-image").forEach((slider) => {
  const slides = slider.querySelectorAll("img");
  slides[0].classList.add("active");

  // One image only: no arrows or dots needed
  if (slides.length < 2) return;

  let current = 0;
  let timer;

  // Arrows
  const prevBtn = document.createElement("button");
  prevBtn.className = "slider-btn prev";
  prevBtn.setAttribute("aria-label", "Previous image");
  prevBtn.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';

  const nextBtn = document.createElement("button");
  nextBtn.className = "slider-btn next";
  nextBtn.setAttribute("aria-label", "Next image");
  nextBtn.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';

  // Dots
  const dotsWrap = document.createElement("div");
  dotsWrap.className = "slider-dots";
  const dots = [...slides].map((_, i) => {
    const dot = document.createElement("button");
    dot.setAttribute("aria-label", "Go to image " + (i + 1));
    dot.addEventListener("click", () => {
      goTo(i);
      restart();
    });
    dotsWrap.appendChild(dot);
    return dot;
  });

  slider.append(prevBtn, nextBtn, dotsWrap);

  function goTo(index) {
    slides[current].classList.remove("active");
    dots[current].classList.remove("active");
    current = (index + slides.length) % slides.length;
    slides[current].classList.add("active");
    dots[current].classList.add("active");
  }

  function start() {
    timer = setInterval(() => goTo(current + 1), 4000);
  }
  function stop() {
    clearInterval(timer);
  }
  function restart() {
    stop();
    start();
  }

  prevBtn.addEventListener("click", () => {
    goTo(current - 1);
    restart();
  });
  nextBtn.addEventListener("click", () => {
    goTo(current + 1);
    restart();
  });

  // Pause while the mouse is over the card
  slider.addEventListener("mouseenter", stop);
  slider.addEventListener("mouseleave", start);

  // Swipe on phones
  let touchStartX = 0;
  slider.addEventListener(
    "touchstart",
    (e) => {
      touchStartX = e.changedTouches[0].screenX;
      stop();
    },
    { passive: true },
  );
  slider.addEventListener(
    "touchend",
    (e) => {
      const diff = e.changedTouches[0].screenX - touchStartX;
      if (Math.abs(diff) > 40) goTo(diff < 0 ? current + 1 : current - 1);
      start();
    },
    { passive: true },
  );

  dots[0].classList.add("active");
  start();
});



// Hero background slideshow
const heroSlides = document.querySelectorAll(".hero-slide");

if (heroSlides.length > 0) {
  let heroCurrent = 0;
  heroSlides[0].classList.add("active");

  if (heroSlides.length > 1) {
    setInterval(() => {
      heroSlides[heroCurrent].classList.remove("active");
      heroCurrent = (heroCurrent + 1) % heroSlides.length;
      heroSlides[heroCurrent].classList.add("active");
    }, 5000);
  }
}