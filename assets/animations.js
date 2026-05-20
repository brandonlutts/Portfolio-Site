gsap.registerPlugin(ScrollTrigger);

if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {

  // Reveal up elements
  gsap.utils.toArray('.js-reveal-up').forEach((el) => {
    gsap.fromTo(el,
      { y: 32, opacity: 0 },
      {
        y: 0,
        opacity: 1,
        duration: 2.0,
        ease: 'power3.out',
        scrollTrigger: {
          trigger: el,
          start: 'top 88%',
          once: true
        }
      }
    );
  });

  // Stagger grids
  gsap.utils.toArray('.js-stagger-grid').forEach((grid) => {
    const items = grid.querySelectorAll('.js-stagger-item');

    gsap.fromTo(items,
      { y: 48, opacity: 0 },
      {
        y: 0,
        opacity: 1,
        duration: 2.0,
        ease: 'power3.out',
        stagger: {
          each: 0.12,
          from: 'random'
        },
        scrollTrigger: {
          trigger: grid,
          start: 'top 82%',
          once: true
        }
      }
    );
  });

}

gsap.registerPlugin(ScrollTrigger);

if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {

  // existing reveal-up code can stay above or below this
  //below is the services section gsap code

  gsap.utils.toArray('.js-services-reveal').forEach((section) => {
    const title = section.querySelector('.js-reveal-up');
    const cards = section.querySelectorAll('.js-services-card');
    const controls = section.querySelectorAll('.js-services-control-item');

    const tl = gsap.timeline({
      scrollTrigger: {
        trigger: section,
        start: 'top 72%',
        once: true
      }
    });

    if (title) {
      tl.fromTo(title,
        {
          y: 28,
          opacity: 0
        },
        {
          y: 0,
          opacity: 1,
          duration: 0.9,
          ease: 'power3.out'
        }
      );
    }

    if (cards.length) {
      tl.fromTo(cards,
        {
          x: -60,
          opacity: 0
        },
        {
          x: 0,
          opacity: 1,
          duration: 0.85,
          ease: 'power3.out',
          stagger: 0.12
        },
        '-=0.35'
      );
    }

    if (controls.length) {
      tl.fromTo(controls,
        {
          x: -28,
          opacity: 0
        },
        {
          x: 0,
          opacity: 1,
          duration: 0.65,
          ease: 'power3.out',
          stagger: 0.08
        },
        '-=0.25'
      );
    }
  });

}

//projects gsap 

gsap.utils.toArray('.js-projects-reveal').forEach((section) => {
  const title = section.querySelector('.h2');
  const hr = section.querySelector('.hr');
  const filters = section.querySelectorAll('.js-project-filter-btn');
  const cards = section.querySelectorAll('.js-project-card');
  const moreLink = section.querySelector('[style*="margin-top:16px"]');

  const tl = gsap.timeline({
    scrollTrigger: {
      trigger: section,
      start: 'top 72%',
      once: true
    }
  });

  if (title) {
    tl.fromTo(title,
      {
        y: 28,
        opacity: 0
      },
      {
        y: 0,
        opacity: 1,
        duration: 0.9,
        ease: 'power3.out'
      }
    );
  }

  if (hr) {
    tl.fromTo(hr,
      {
        y: 18,
        opacity: 0
      },
      {
        y: 0,
        opacity: 1,
        duration: 0.75,
        ease: 'power3.out'
      },
      '-=0.45'
    );
  }

  if (filters.length) {
    tl.fromTo(filters,
      {
        x: -36,
        opacity: 0
      },
      {
        x: 0,
        opacity: 1,
        duration: 0.7,
        ease: 'power3.out',
        stagger: 0.08
      },
      '-=0.3'
    );
  }

  if (cards.length) {
    tl.fromTo(cards,
      {
        y: 56,
        opacity: 0
      },
      {
        y: 0,
        opacity: 1,
        duration: 0.9,
        ease: 'power3.out',
        stagger: {
          each: 0.12,
          from: 'start'
        }
      },
      '-=0.15'
    );
  }

  if (moreLink) {
    tl.fromTo(moreLink,
      {
        y: 24,
        opacity: 0
      },
      {
        y: 0,
        opacity: 1,
        duration: 0.7,
        ease: 'power3.out'
      },
      '-=0.25'
    );
  }
});