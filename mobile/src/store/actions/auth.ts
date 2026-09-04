import {createAsyncThunk} from '@reduxjs/toolkit';

import {getExceptionPayload, type APIError} from '../../constants/apiErrors';
import {
  deleteRemoteAccount,
  type AccountDeletionResult,
} from '../../services/accountDeletion';

export type {AccountDeletionResult} from '../../services/accountDeletion';

/**
 * Redux owns account deletion. Session creation and refresh stay in
 * `services/socialAuth` as the single deployed authentication path.
 */
export const deleteAccount = createAsyncThunk<
  AccountDeletionResult,
  {reauthToken: string},
  {rejectValue: APIError}
>('auth/deleteAccount', async ({reauthToken}, {rejectWithValue}) => {
  try {
    return await deleteRemoteAccount(reauthToken);
  } catch (error) {
    return rejectWithValue(getExceptionPayload(error));
  }
});
