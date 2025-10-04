
(function(){
    function throttle(fn, wait){
        let time = Date.now();
        return function(){
            if ((time + wait - Date.now()) < 0){
                fn.apply(this, arguments);
                time = Date.now();
            }
        }
    }

    function initStory(root){
        const sections = Array.from(root.querySelectorAll('.isb-section'));
        if (!sections.length) return;

        // IntersectionObserver to set active
        const observer = new IntersectionObserver((entries)=>{
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('isb-active');
                } else {
                    entry.target.classList.remove('isb-active');
                }
            });
        }, { threshold: 0.5 });

        sections.forEach(sec => observer.observe(sec));

        // Optional 'pin' behaviour: make a pinned element fixed while scrolling within range
        const pinned = sections.filter(s => s.dataset.pin === 'true');
        pinned.forEach(p => {
            const inner = p.querySelector('.isb-section-inner');
            if (!inner) return;
            const sticky = function(){
                const rect = p.getBoundingClientRect();
                if (rect.top <= 0 && rect.bottom > window.innerHeight) {
                    inner.classList.add('isb-pinned');
                } else {
                    inner.classList.remove('isb-pinned');
                }
            };
            window.addEventListener('scroll', throttle(sticky, 50));
            sticky();
        });
    }

    document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('.isb-story').forEach(initStory);
    });
})();
