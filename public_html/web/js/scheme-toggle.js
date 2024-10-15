window.onload = () => {
  const themeVersion = "v1.0.1";
  /* Load light or dark theme from preferences or localStorage when set.. */
  const prefersDarkScheme = window.matchMedia("(prefers-color-scheme: dark)");
  let currentScheme = localStorage.getItem("theme-" + themeVersion);
  if (currentScheme == "dark" && !prefersDarkScheme.matches) {
    document.body.parentElement.classList.add("dark");
  } else if (currentScheme == "light" && prefersDarkScheme.matches) {
    document.body.parentElement.classList.add("light");
  }
  document.body.parentElement.style.visibility = "visible";
  document.body.parentElement.style.opacity = 1;

  /* Handle scheme-toggle buttons logic.. */
  let btns = document.querySelectorAll("input.scheme-toggle");
  btns.forEach((btn, i) => {
    btn.addEventListener("click", () => {
      currentScheme = localStorage.getItem("theme-" + themeVersion);
      if (currentScheme == null) {
        if (prefersDarkScheme.matches) {
          document.body.parentElement.classList.toggle("light");
        } else {
          document.body.parentElement.classList.toggle("dark");
        }
      } else {
        let toggle = "light";
        if (currentScheme == "light") {
          toggle = "dark";
        }
        document.body.parentElement.classList.toggle(toggle);
        document.body.parentElement.classList.remove(currentScheme);
      }
      let theme = "light";
      if (document.body.parentElement.classList.contains("dark") ||
        (!document.body.parentElement.classList.contains("dark") && !document.body.parentElement.classList.contains("light") && prefersDarkScheme.matches)
      ) {
        theme = "dark";
      }
      localStorage.setItem("theme-" + themeVersion, theme);
    });
  });
}
