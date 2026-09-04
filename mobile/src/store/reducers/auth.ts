import {createSlice, PayloadAction} from '@reduxjs/toolkit';

export interface AuthState {
  /** Canonical authenticated session mirrored from secure storage. */
  userData: unknown;
  isLogin: boolean;
}

const initialState: AuthState = {
  userData: {},
  isLogin: false,
};

const authSlice = createSlice({
  name: 'auth',
  initialState,
  reducers: {
    LogOut: state => {
      state.userData = {};
      state.isLogin = false;
    },
    saveLoginData: (state, action: PayloadAction<unknown>) => {
      state.userData = action.payload;
      state.isLogin = true;
    },
  },
});

export const {LogOut, saveLoginData} = authSlice.actions;
export default authSlice.reducer;
