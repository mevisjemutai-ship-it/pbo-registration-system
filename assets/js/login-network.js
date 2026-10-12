/*
 * Sign-in page background: a slow network of points in PBORA colours.
 * Points drift, link to nearby points, move away from the pointer,
 * and bounce outward from a click or tap. Static when the visitor
 * prefers reduced motion.
 */
(function () {
    'use strict';

    var canvas = document.createElement('canvas');
    canvas.className = 'login-network';
    canvas.setAttribute('aria-hidden', 'true');
    document.body.insertBefore(canvas, document.body.firstChild);

    var ctx = canvas.getContext('2d');
    if (!ctx) { return; }

    var reduceMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var colours = [
        [46, 74, 156],   // PBORA blue
        [46, 74, 156],
        [46, 74, 156],
        [192, 149, 47],  // gold
        [200, 32, 47]    // red
    ];

    var points = [];
    var ripples = [];
    var pointer = { x: -9999, y: -9999 };
    var width = 0;
    var height = 0;
    var ratio = 1;
    var linkDistance = 140;

    function resize() {
        ratio = Math.min(window.devicePixelRatio || 1, 2);
        width = window.innerWidth;
        height = window.innerHeight;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

        var target = Math.min(95, Math.round(width * height / 15000));
        while (points.length < target) { points.push(makePoint()); }
        points.length = target;
    }

    function makePoint() {
        var c = colours[Math.floor(Math.random() * colours.length)];
        return {
            x: Math.random() * width,
            y: Math.random() * height,
            vx: (Math.random() - 0.5) * 0.35,
            vy: (Math.random() - 0.5) * 0.35,
            r: 1.6 + Math.random() * 2.4,
            c: c
        };
    }

    function step() {
        for (var i = 0; i < points.length; i++) {
            var p = points[i];

            // Move away from the pointer.
            var dx = p.x - pointer.x;
            var dy = p.y - pointer.y;
            var d2 = dx * dx + dy * dy;
            if (d2 < 14400 && d2 > 0.01) {
                var d = Math.sqrt(d2);
                var push = (120 - d) / 120 * 0.6;
                p.vx += dx / d * push;
                p.vy += dy / d * push;
            }

            // Gentle drag back towards a calm drift.
            p.vx *= 0.97;
            p.vy *= 0.97;
            if (Math.abs(p.vx) < 0.08) { p.vx += (Math.random() - 0.5) * 0.04; }
            if (Math.abs(p.vy) < 0.08) { p.vy += (Math.random() - 0.5) * 0.04; }

            p.x += p.vx;
            p.y += p.vy;

            // Bounce off the edges.
            if (p.x < 0) { p.x = 0; p.vx = Math.abs(p.vx); }
            if (p.x > width) { p.x = width; p.vx = -Math.abs(p.vx); }
            if (p.y < 0) { p.y = 0; p.vy = Math.abs(p.vy); }
            if (p.y > height) { p.y = height; p.vy = -Math.abs(p.vy); }
        }

        for (var k = ripples.length - 1; k >= 0; k--) {
            ripples[k].r += 5;
            ripples[k].a -= 0.018;
            if (ripples[k].a <= 0) { ripples.splice(k, 1); }
        }
    }

    function draw() {
        ctx.clearRect(0, 0, width, height);

        for (var i = 0; i < points.length; i++) {
            var a = points[i];
            for (var j = i + 1; j < points.length; j++) {
                var b = points[j];
                var dx = a.x - b.x;
                var dy = a.y - b.y;
                var d = Math.sqrt(dx * dx + dy * dy);
                if (d < linkDistance) {
                    ctx.strokeStyle = 'rgba(46, 74, 156,' + (0.16 * (1 - d / linkDistance)).toFixed(3) + ')';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(a.x, a.y);
                    ctx.lineTo(b.x, b.y);
                    ctx.stroke();
                }
            }
        }

        for (var n = 0; n < points.length; n++) {
            var p = points[n];
            ctx.fillStyle = 'rgba(' + p.c[0] + ',' + p.c[1] + ',' + p.c[2] + ',0.55)';
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
            ctx.fill();
        }

        for (var k = 0; k < ripples.length; k++) {
            var rp = ripples[k];
            ctx.strokeStyle = 'rgba(192, 149, 47,' + rp.a.toFixed(3) + ')';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.arc(rp.x, rp.y, rp.r, 0, Math.PI * 2);
            ctx.stroke();
        }
    }

    function loop() {
        if (!document.hidden) {
            step();
            draw();
        }
        window.requestAnimationFrame(loop);
    }

    function burst(x, y) {
        ripples.push({ x: x, y: y, r: 4, a: 0.7 });
        for (var i = 0; i < points.length; i++) {
            var p = points[i];
            var dx = p.x - x;
            var dy = p.y - y;
            var d = Math.sqrt(dx * dx + dy * dy) || 1;
            if (d < 260) {
                var force = (260 - d) / 260 * 6;
                p.vx += dx / d * force;
                p.vy += dy / d * force;
            }
        }
    }

    window.addEventListener('resize', function () {
        resize();
        if (reduceMotion) { draw(); }
    });

    if (reduceMotion) {
        resize();
        draw();
        return;
    }

    window.addEventListener('pointermove', function (e) {
        pointer.x = e.clientX;
        pointer.y = e.clientY;
    });

    document.addEventListener('pointerleave', function () {
        pointer.x = -9999;
        pointer.y = -9999;
    });

    // Clicks on the background (not on the form) make the points bounce.
    document.addEventListener('pointerdown', function (e) {
        if (e.target.closest && e.target.closest('.login-card')) { return; }
        burst(e.clientX, e.clientY);
    });

    resize();
    loop();
})();
