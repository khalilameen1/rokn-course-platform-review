import {learnerErrorMessage} from '../src/utils/errorPayload';
import {learnerCopy} from '../src/constants/learnerCopy';

describe('learner error copy boundary', () => {
  it('does not expose an English API diagnostic to the learner', () => {
    expect(
      learnerErrorMessage(
        {data: {message: 'Internal server error'}},
        learnerCopy.retry,
      ),
    ).toBe(learnerCopy.retry);
  });

  it('keeps a useful Arabic validation message', () => {
    expect(
      learnerErrorMessage(
        {data: {errors: {portfolio_slug: ['اسم المستخدم مستخدم بالفعل']}}},
        learnerCopy.retry,
      ),
    ).toBe('اسم المستخدم مستخدم بالفعل');
  });
});
