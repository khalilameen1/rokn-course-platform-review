import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

// Audit the final rendered state without disabling the native motion or
// changing axe rules/results. Infinite progress indicators are not transitions.
export default class SettledAxeBuilder extends AxeBuilder {
    constructor(options) {
        super(options);
        this.dashboardPage = options.page;
    }

    async analyze() {
        await expect.poll(() => this.dashboardPage.evaluate(() =>
            document.getAnimations().filter(animation =>
                animation.playState === 'running'
                && animation.effect?.getComputedTiming().endTime !== Infinity
            ).length
        ), { message: 'Native dashboard transitions must finish before the accessibility audit' }).toBe(0);

        return super.analyze();
    }
}
