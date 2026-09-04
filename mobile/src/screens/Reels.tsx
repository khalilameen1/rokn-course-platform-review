import React from 'react';
import ReelsSurface from './reels/ReelsSurface';
import {useReelsController} from './reels/useReelsController';

const Reels = () => <ReelsSurface {...useReelsController()} />;

export default Reels;
